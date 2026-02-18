# FFL Funnels Addons

**Custom addons and integrations for FFL Funnels WooCommerce stores.**

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0+-blue.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0+-violet.svg)
![PHP](https://img.shields.io/badge/PHP-7.4+-green.svg)

## 🚀 Features

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

### 3. Doofinder Sync
*   Automatically injects product metadata for Doofinder search indexing.
*   Ensures your search engine always has the latest product data.

## 🛠️ Installation

1.  Download the `ffl-funnels-addons.zip` file from the [Releases](https://github.com/aaruca/ffl-funnels-addons/releases) page.
2.  Go to **WordPress Admin > Plugins > Add New**.
3.  Click **Upload Plugin** and select the zip file.
4.  Activate the plugin.
5.  Go to **FFL Addons** in the admin menu to configure modules.

## ⚙️ Configuration

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

## 📦 Requirements

*   WordPress 6.0 or higher
*   WooCommerce 8.0 or higher
*   PHP 7.4 or higher
*   (Optional) Bricks Builder for visual layout customization

## 📝 Changelog

### v1.0.0
*   Initial release.
*   Added WooBooster module with Rules Engine and Smart Recommendations.
*   Added Wishlist module.
*   Added Doofinder Sync module.
*   Implemented modular architecture and GitHub Updater.

## 👤 Author

**Ale Aruca**

---
*For internal use by FFL Funnels clients.*
