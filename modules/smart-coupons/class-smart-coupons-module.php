<?php
/**
 * Smart Coupons module.
 *
 * Extends WooCommerce coupons for FFL stores:
 * - Guardrails: firearms and protected categories/tags are never discounted
 *   unless a coupon allows it, and no coupon pushes a product below its
 *   minimum (MAP) price.
 * - Conditions: start date, first order, customer roles, minimum quantity
 *   from categories, pickup / shipping, states, payment methods.
 * - Discount types: spend tiers, buy X get Y, a cap on percentage coupons
 *   and a free gift added to the cart.
 * - Store credit with a running balance (also issued from customer requests).
 * - Bulk single-use codes, coupon links (?coupon=CODE), stacking rules with
 *   "best discount wins", per-customer limits and attempt throttling.
 * - Coupon categories (Marketing → Coupon categories) with colours, list
 *   filter, and per-category rules: one per order, cannot combine with other
 *   categories, discount cap, firearms allowed, customer roles, default
 *   expiry and a monthly discount budget.
 * - Report of orders, revenue, discount cost and new customers per coupon and
 *   per category.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class Smart_Coupons_Module extends FFLA_Module
{
    const PAGE_SETTINGS = 'ffla-coupons';
    const PAGE_CODES = 'ffla-coupons-codes';
    const PAGE_CREDIT = 'ffla-coupons-credit';
    const PAGE_REPORT = 'ffla-coupons-report';

    public function get_id(): string
    {
        return 'smart-coupons';
    }

    public function get_name(): string
    {
        return __('Smart Coupons', 'ffl-funnels-addons');
    }

    public function get_description(): string
    {
        return __('Coupon guardrails for firearms and MAP prices, extra conditions, spend tiers, buy X get Y, gifts, store credit, bulk codes, coupon links, coupon categories with budgets and a coupon report.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 8a2 2 0 002-2h14a2 2 0 002 2v2a2 2 0 000 4v2a2 2 0 00-2 2H5a2 2 0 00-2-2v-2a2 2 0 000-4V8z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M9 15l6-6M9.5 9.5h.01M14.5 14.5h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    }

    public function boot(): void
    {
        foreach (['settings', 'categories', 'rules', 'discounts', 'credit', 'codes', 'report', 'admin'] as $part) {
            require_once __DIR__ . '/includes/class-ffla-coupon-' . $part . '.php';
        }
        FFLA_Coupon_Categories::boot();
        FFLA_Coupon_Rules::boot();
        FFLA_Coupon_Discounts::boot();
        FFLA_Store_Credit::boot();
        FFLA_Coupon_Codes::boot();
        if (is_admin()) {
            FFLA_Coupon_Admin::boot();
        }
    }

    public function activate(): void
    {
    }

    public function deactivate(): void
    {
    }

    public function get_admin_pages(): array
    {
        return [
            ['slug' => self::PAGE_SETTINGS, 'title' => __('Coupon Settings', 'ffl-funnels-addons')],
            ['slug' => self::PAGE_CODES, 'title' => __('Bulk Codes', 'ffl-funnels-addons')],
            ['slug' => self::PAGE_CREDIT, 'title' => __('Store Credit', 'ffl-funnels-addons')],
            ['slug' => self::PAGE_REPORT, 'title' => __('Coupon Report', 'ffl-funnels-addons')],
        ];
    }

    public function render_admin_page(string $page_slug): void
    {
        switch ($page_slug) {
            case self::PAGE_SETTINGS:
                FFLA_Coupon_Admin::settings_page();
                break;
            case self::PAGE_CODES:
                FFLA_Coupon_Codes::page();
                break;
            case self::PAGE_CREDIT:
                FFLA_Store_Credit::page();
                break;
            case self::PAGE_REPORT:
                FFLA_Coupon_Report::page();
                break;
        }
    }
}
