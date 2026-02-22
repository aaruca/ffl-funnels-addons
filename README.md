# FFL Funnels Addons

**Custom addons and integrations for FFL Funnels WooCommerce stores.**

![Version](https://img.shields.io/badge/version-1.3.1-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0+-blue.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0+-violet.svg)
![PHP](https://img.shields.io/badge/PHP-7.4+-green.svg)

## Features

This plugin is a modular suite of tools designed to enhance FFL Funnels stores. It includes:

### 1. WooBooster Module
An intelligent product recommendation engine that goes beyond simple "related products".
*   **Targeted Rules:** Create specific recommendation rules based on Categories, Tags, and Attributes (e.g., recommend specific holsters for Glock 19).
*   **Smart Recommendations:** Automatically display "Bought Together", "Trending", "Recently Viewed", and "Similar Products" without manual curation.
*   **High Performance:** Uses custom index tables and aggressive caching to ensure zero impact on page load speed.
*   **Bricks Integration:** Fully compatible with Bricks Builder via a custom "WooBooster Recommendations" Query Type.

### 2. Wishlist Module
A lightweight wishlist implementation optimized for performance.
*   Item toggling via AJAX.
*   Bricks Builder integration.
*   Guest wishlist support.
*   Doofinder shadow DOM integration.

### 3. Doofinder Sync
*   Automatically injects product metadata for Doofinder search indexing.
*   Ensures your search engine always has the latest product data.

## Installation

1.  Download the `ffl-funnels-addons.zip` file from the [Releases](https://github.com/aaruca/ffl-funnels-addons/releases) page.
2.  Go to **WordPress Admin > Plugins > Add New**.
3.  Click **Upload Plugin** and select the zip file.
4.  Activate the plugin.
5.  Go to **FFL Addons** in the admin menu to configure modules.

## Auto-Updates

The plugin supports automatic updates via GitHub Releases. When a new version is published, WordPress will detect it and offer the update in the Plugins page.

For private repositories, add this to `wp-config.php`:
```php
define('FFLA_GITHUB_TOKEN', 'ghp_your_token_here');
```

## Configuration

### Activating Modules
The plugin is modular. You can enable or disable features to keep your site lightweight.
1.  Navigate to **FFL Addons > Dashboard**.
2.  Toggle the switches for the modules you want to use (e.g., WooBooster, Wishlist).
3.  Click the "Settings" button on active cards to configure specific options.

### WooBooster Rules
1.  Go to **FFL Addons > WooBooster > Rules**.
2.  Click **Add Rule**.
3.  **Conditions:** Define *when* this rule applies (e.g., "Product Category is Firearms").
4.  **Actions:** Define *what* to show (e.g., "Show products from Category: Ammo" OR "Show Related Products from Attribute: Caliber").
5.  **Priority:** Rules are processed top-to-bottom. The first matching rule wins.

## Requirements

*   WordPress 6.0 or higher
*   WooCommerce 8.0 or higher
*   PHP 7.4 or higher
*   (Optional) Bricks Builder for visual layout customization

## Changelog

### v1.3.1
*   Fix: Prevent fatal error (Cannot redeclare class/function) when multiple plugin directories exist during updates.

### v1.3.0
*   Format rule lists and admin configuration to use strict design system classes (Tailwind equivalents).
*   Add issue and PR templates (.github/ISSUE_TEMPLATE) to enforce contribution standards.
*   Add `.editorconfig` for formatting unification.
*   Add standard `LICENSE.md` file.

### v1.2.3
*   Fix updater not detecting updates via WP-Cron (moved initialization outside `is_admin()`).
*   Fix potential TypeError in updater by removing strict object type hint in `check_update`.
*   Various rule UI style and template updates.

### v1.2.2
*   UI Style overhaul for the rule form.

### v1.2.1
*   Add rule scheduling — set start/end dates for time-limited rules (promotions, seasonal campaigns).
*   Add search/filter on the rules list page.
*   Add `not_equals` operator for conditions ("Category is not X").
*   Add rule duplicate button (creates inactive copy with conditions and actions).
*   Add sticky save bar on rule form.
*   Improve rule list columns: human-readable condition summaries with operator, resolved term names, and action labels for all source types.
*   Improve exclusion panels: visual distinction between Condition Exclusions (blue) and Action Exclusions (green).
*   Fix `min_quantity` tooltip to clarify it only applies to coupon/cart rules.
*   Fix `specific_product` single-condition rules not found via index lookup.
*   Fix scheduling enforced in both product matcher and coupon auto-apply engine.
*   Bump DB version to 1.7.0 (adds `start_date`/`end_date` columns with safe migration).

### v1.2.0
*   Add Conditional Coupon System — auto-apply/remove WooCommerce coupons when rule conditions match cart contents.
*   Add "Apply Coupon" action type with coupon search and expiry/usage guard.
*   Add "Specific Products" action type for hand-picked product recommendations.
*   Add "Specific Product" condition type to trigger rules for individual products.
*   Add condition-level exclusions: exclude by category, product, or price range.
*   Add minimum quantity threshold per condition for coupon and recommendation matching.
*   Add custom cart notice when a coupon is auto-applied.
*   New `WooBooster_Coupon` class with WC session-based tracking.

### v1.1.1
*   Analytics dashboard overhaul: single-pass queries, trend indicators, donut chart, funnel visualization, product thumbnails.
*   Add expanded date range presets (today, yesterday, 7d, 30d, 90d, year, all-time).
*   Add Revenue Chart to analytics dashboard.
*   Fix updater API notice dismiss.

### v1.1.0
*   Add WooBooster Analytics dashboard — track revenue, conversion, and top rules/products from recommendations.
*   Add JS attribution tracking: intercepts WooCommerce AJAX add-to-cart to tag items from WooBooster recommendations.
*   Add `_wb_source_rule` order line item meta for recommendation attribution persistence.
*   Add add-to-cart counter and conversion rate metrics.
*   Add date range filter with 7d/30d/90d presets.
*   Expose `WooBooster_Matcher::$last_matched_rule` for cross-class context sharing.

### v1.0.22
*   Fix wishlist count badge not updating via AJAX (class mismatch `.ffla-wishlist-count` vs `.alg-wishlist-count`).
*   Fix wishlist empty page showing plain text instead of styled "Return to Shop" block.
*   Fix button title attributes using past-tense toast messages instead of action text.
*   Update Doofinder documentation snippet to match working production code (`window.AlgWishlist.toggle`).
*   Add `[alg_wishlist_button_aws]` shortcode to admin documentation.

### v1.0.21
*   Security audit: fix CSS injection in wishlist color settings (validate with `sanitize_hex_color` pattern).
*   Security audit: fix XSS in wishlist shortcode `icon` attribute (sanitize SVG with `wp_kses`).
*   Security audit: escape `$product->get_name()` in wishlist page shortcode.
*   Add ABSPATH guards to all wishlist include files.
*   Clean up dead code in WooBooster `ajax_delete_all_rules`.
*   Add `.gitattributes` for clean GitHub release zips (exclude dev files).
*   Add GitHub Actions workflow for automated release builds.
*   Remove stale `build/` directory from git tracking.

### v1.0.20
*   Switched to `upgrader_source_selection` to fix plugin folder rename during updates.

### v1.0.17
*   Added `[alg_wishlist_button_aws]` shortcode with text toggles.

### v1.0.15
*   Wishlist JS sync for Doofinder shadow DOM layers.
*   Toast notification improvements.

### v1.0.3
*   Removed global category rules to prevent redundancy.
*   Added "Delete All Rules" bulk action in admin.
*   Improved rule efficiency.

### v1.0.0
*   Initial release.
*   Added WooBooster module with Rules Engine and Smart Recommendations.
*   Added Wishlist module.
*   Added Doofinder Sync module.
*   Implemented modular architecture and GitHub Updater.

## Author

**Ale Aruca**

---
*For internal use by FFL Funnels clients.*
