# Merge Orders for WooCommerce (Sorted & Grouped, 3+)

**Contributors:** hostify  
**Tags:** woocommerce, orders, merge orders, hpos, order management, auctions  
**Requires at least:** WordPress 6.4.3  
**Tested up to:** WordPress 6.9  
**Requires PHP:** 8.1  
**Requires WooCommerce:** 8.0+  
**Stable tag:** 3.1.0  
**License:** GPL2  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

Merge multiple eligible WooCommerce orders from the same customer into a single order. Includes date filtering, customer-group pagination, and optional line-item consolidation. Fully compatible with WooCommerce HPOS and supports YITH WooCommerce Auctions.

## Description

This plugin provides an admin tool under **WooCommerce → Merge Orders** that groups eligible orders by customer and allows you to merge selected orders into one.

### Key features

- **Grouped UI with per-customer form** to prevent cross-customer merges
- **Default 3+ visibility** (only customers with 3+ eligible orders are shown by default; configurable)
- **Date range filter + groups pagination** for performance on large stores
- **Optional line-item consolidation** (same product/variation + meta fingerprint → summed quantity/totals)
- Copies **products (with item meta)**, **shipping lines**, **fees**, and **coupons**
- **Server-side validation**: same customer + same currency
- **Card gateway safeguard**: blocks merges for card-paid orders (filterable list)
- **Stock safety**: avoids double stock reductions and blocks unsafe merges
- **Email behavior**: triggers Customer Invoice email for merged orders that end in **Pending**
- **YITH WooCommerce Auctions integration**: places auction-won orders on-hold (if YITH Auctions is active)

Default eligible statuses: `pending`, `on-hold`, `processing` (filterable).

## Installation

1. Upload the plugin files to `/wp-content/plugins/` (or install via the WordPress Plugins screen).
2. Activate the plugin in **Plugins**.
3. Open **WooCommerce → Merge Orders**.

## Usage

1. (Optional) Use **From/To** date filters and set **Groups per page**.
2. Select the orders you want to merge within a customer group.
3. Click **Merge / Process Selected Orders**.
4. Review the confirmation screen and open the newly created order.

## Configuration (filters)

Show customers with **2+** eligible orders:

```php
add_filter('hostify_merge_orders_min_group_size', function () {
    return 2;
});
```

Change eligible statuses:

```php
add_filter('hostify_merge_orders_eligible_statuses', function () {
    return array('pending', 'on-hold');
});
```

Disable line-item consolidation:

```php
add_filter('hostify_merge_orders_consolidate_line_items', function () {
    return false;
});
```

Extend the blocked card gateway list:

```php
add_filter('hostify_merge_orders_card_payment_methods', function ($methods) {
    $methods[] = 'your_gateway_id';
    return $methods;
});
```

## Changelog

### 3.1.0
- Added date range filtering and customer-group pagination in admin.
- Added optional product line-item consolidation.
- Added Customer Name column in the customer tables.
- Improved stock safety and blocked unsafe merges.
- Updated uninstall behavior and documentation.

### 3.0.0
- Refactor: per-customer merge forms + strict validation (same customer/currency).
- Fixed status handling (`pending`).
- Copies shipping, fees, coupons, and item meta.
- Improved HPOS compatibility and admin UX.

### 1.1
- Added support for YITH WooCommerce Auctions.
- Improved compatibility with WooCommerce HPOS.

### 1.0
- Initial release.

## Upgrade Notice

### 3.1.0
Includes date filtering, pagination, optional line-item consolidation, and improved stock safety.
