=== Merge Orders for WooCommerce (Sorted & Grouped, 3+) ===
Contributors: hostify
Tags: woocommerce, orders, merge orders, hpos, order management, auctions
Requires at least: 6.4.3
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 3.1.0
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Merge multiple eligible WooCommerce orders from the same customer into a single order. Includes date filtering, customer-group pagination, and optional line-item consolidation. Fully compatible with WooCommerce HPOS and supports YITH WooCommerce Auctions.

== Description ==

Merge Orders for WooCommerce (Sorted & Grouped, 3+) helps store owners reduce admin overhead by merging multiple eligible orders from the same customer into one order.

Key features:

* **Grouped admin UI** under *WooCommerce → Merge Orders*, grouped by customer with a dedicated merge form per customer (prevents cross-customer merging).
* **Default 3+ group visibility**: only customers with **3 or more eligible orders** are shown by default (configurable to 2+ via filter).
* **Date range filter + pagination**: filter by order created date and paginate customer groups for better performance on large stores.
* **Optional product line consolidation**: identical products/variations (including relevant item meta) can be consolidated into a single line with summed quantity/totals (enabled by default; filterable).
* **Copies key order items** into the merged order: products (with item meta), shipping lines, fees, and coupons.
* **Safe validations**: selected orders must belong to the same customer and use the same currency.
* **Card-payment safeguard**: merging is blocked for orders paid via card gateways (Stripe/WCPay/etc.). The list is filterable.
* **Stock safety**: prevents stock mismatches when merging orders that already reduced stock; reduces stock only once on the final merged order when appropriate.
* **Email behavior**: for merged orders that end in **Pending** status, the plugin triggers the **Customer Invoice** email to provide a payment link.
* **YITH WooCommerce Auctions support**: when an auction is won, the related order can be placed on-hold (if the integration is active).

Default eligible statuses are: **pending**, **on-hold**, and **processing** (filterable).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/` directory (or install via the WordPress Plugins screen).
2. Activate the plugin through the **Plugins** screen.
3. Go to **WooCommerce → Merge Orders**.

== Usage ==

1. (Optional) Use the **From/To** date filters and set **Groups per page**.
2. For a customer group, select the orders you wish to merge.
3. Click **Merge / Process Selected Orders**.
4. A confirmation screen will show the new merged order.

== Frequently Asked Questions ==

= What versions of WooCommerce are supported? =
WooCommerce 8.0+ is required.

= Can I merge orders from different customers? =
No. The plugin enforces same-customer merging (user ID or guest email) and validates this on the server.

= Can I merge only two orders? (2+) =
Yes. The merge logic supports 2 orders; the admin list default is 3+ for noise reduction. To show 2+ groups, add:

    add_filter('hostify_merge_orders_min_group_size', function () {
        return 2;
    });

= Which order statuses are eligible by default? =
pending, on-hold, processing. You can change this with:

    add_filter('hostify_merge_orders_eligible_statuses', function () {
        return array('pending', 'on-hold');
    });

= How do I disable line-item consolidation? =
    add_filter('hostify_merge_orders_consolidate_line_items', function () {
        return false;
    });

= Can I extend the list of card payment gateways that should be blocked? =
Yes, using either filter:

    add_filter('hostify_merge_orders_card_payment_methods', function ($methods) {
        $methods[] = 'your_gateway_id';
        return $methods;
    });

== Screenshots ==

1. Merge Orders admin page with date range filters and customer-group pagination.
2. Customer group table (includes Customer Name column) and the merge action button.
3. Merge confirmation page showing the created order.

== Changelog ==

= 3.1.0 =
* Added date range filtering and customer-group pagination in the admin UI.
* Added optional line-item consolidation for merged orders.
* Added Customer Name column in the order tables.
* Improved stock safety and prevented stock mismatch merges.
* Updated and aligned documentation and uninstall behavior.

= 3.0.0 =
* Refactor: per-customer merge forms, strict same-customer/same-currency validation.
* Fixed status handling (uses `pending`).
* Copy shipping, fees, coupons, and item meta into the merged order.
* Improved HPOS compatibility and admin UX.

= 1.1 =
* Added support for YITH WooCommerce Auctions.
* Improved compatibility with WooCommerce HPOS.

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 3.1.0 =
Adds date filtering, pagination, and optional line-item consolidation, plus improved stock safety.
