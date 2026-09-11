# Pickup & Shipping: integration and QA

The module is registered but never automatically activated or configured. Production sites are not changed by building this code.

## Activation

1. Keep the site on its current release until staging verification is complete.
2. Enable **Pickup & Shipping** under FFL Funnels modules.
3. Select enabled shipping-zone method instances in General. Empty/incomplete settings leave checkout unchanged.
4. Select automatic placement or use `[ffla_delivery_choice]` inside the classic `form.checkout`. A shortcode outside the form cannot submit a choice.
5. Optionally enable FFL rules with g-FFL Checkout active. The addon reads the native `ffl_local_pickup` setting directly and displays the detected license read-only. Configure the local FFL only in FFL Checkout, not in this addon.
6. Select the corresponding WooCommerce pickup method instances in General. The existing FFL Checkout local-pickup selector stays in control: its configured local FFL permits pickup rates; another selected FFL permits shipping rates. Prices remain under WooCommerce.
7. Disable the old Camarillo shipping snippet before enabling these rules. If taxes are in the same legacy snippet, separate them first; do not discard tax code.
8. Confirm pickup cost in WooCommerce. The addon never makes a method free.

## Site colors

Appearance & Text provides separate container background/text, unselected card background/text, selected card background/text and border colors. Selected cards use a solid fill; keyboard-accessible native radio inputs are visually hidden. The component inherits the site's font.

Colors accept HEX (including alpha), transparent, currentColor or site CSS variables, such as `var(--primary)`, `var(--surface)` and `var(--text)`. A bare `--primary` is normalized to `var(--primary)`. Optional fallbacks work too: `var(--primary, #2271b1)` or `var(--primary, var(--brand, #2271b1))`.

Container radius, card radius and gap between cards are independent settings. Corners default to square; gap defaults to 16px. Use nonnegative px/rem/em/% values, 0, or variables with optional fallbacks, such as `var(--radius, 0px)` and `var(--space-m, 16px)`. The addon defines its grid gap explicitly for both desktop and mobile; generic checkout grid rules must not replace it.

References remain live, so changing the site's variables updates the checkout without saving the addon again. Blank settings retain the default site inheritance. Variables must resolve to full CSS colors, not partial RGB/HSL channel values. A theme variable defined only on the storefront cannot resolve in the admin preview; use an explicit fallback if needed. Theme styles are not loaded into wp-admin by this module.

Example dark styling: container #050505, heading #ffffff, card #111111, card text #dddddd, selected background var(--primary, #ff1616), selected text #ffffff, border #333333, both radii 0px, gap 16px. These are optional per-site settings, not hardcoded store branding.

## Support boundaries

- Native FFL Checkout behavior varies by release and store configuration: **CLICK HERE FOR IN STORE PICKUP** may search for the store and may also complete its dealer selection automatically. This addon does not infer intent from the button click. It follows the final license that FFL Checkout posts in its primary or documented backup fields. If a classic WooCommerce fragment redraw empties those hidden fields while FFL Checkout still exposes exactly one selected dealer card, the addon restores the license from that native card and performs one new checkout review. The configured store and its renewed license are matched by ATF's abbreviated identity (first three plus last five digits), so expiration-segment changes do not turn local pickup into shipping. Any other selected FFL receives shipping.
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
- Native local FFL, external FFL, clearing/changing dealer; change or clear the native pickup setting without saving the addon and verify cache invalidation. No fallback based on names, cookies or legacy mappings.
- Variation firearm flags; provider state-compliance carts; provider-specific verified exemptions/selector overrides.
- Multiple regular packages, separated mixed packages, and unsupported unsplit mixed packages.
- Cart → checkout, address edits, coupons, quantity changes, emptied cart, retry after payment failure, restored checkout.
- Check actual server-selected methods match each package after AJAX, not only the visible cards.
- Confirm carrier quotes and taxable addresses remain correct for the site's configured shipping/tax providers.
- Metadata in administration, customer order view, HTML/plain-text emails and HPOS-enabled orders.
- Classic custom templates: shortcode inside form, correct checkout script, no duplicate selector, no old snippet active.
- Disable the module: existing WooCommerce delivery flow remains available.
