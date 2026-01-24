<?php
// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    // Query to select orders with 'wc-merged' status
    $orders_ids = get_posts(array(
        'posts_per_page' => -1,
        'post_type' => 'shop_order',
        'post_status' => 'wc-merged',
        'fields' => 'ids',
    ));

    // Check if we have any orders to update
    if (!empty($orders_ids)) {
        foreach ($orders_ids as $order_id) {
            // Update order status to 'wc-pending' which is the correct status code for pending orders
            wp_update_post(array(
                'ID' => $order_id,
                'post_status' => 'wc-pending'
            ));
        }
    }
}
?>
