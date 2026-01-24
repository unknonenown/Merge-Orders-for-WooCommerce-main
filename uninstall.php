<?php
/**
 * Uninstall routine for Merge Orders for WooCommerce (Sorted & Grouped, 3+)
 *
 * Notes:
 * - Reverts orders that this plugin marked as "Merged" back to "Pending".
 * - Cleans plugin-specific meta keys:
 *     _hostify_merged_into_order_id
 *     _hostify_merged_from_order_ids
 *
 * HPOS-safe: prefers wc_get_orders() when WooCommerce is available.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Batch size to avoid timeouts / memory spikes.
$batch_size = 200;

/**
 * 1) Preferred path (HPOS compatible): use WooCommerce order queries if available.
 */
if (function_exists('wc_get_orders') && class_exists('WC_Order')) {

    // Revert "Merged" source orders created by this plugin.
    do {
        $orders = wc_get_orders(array(
            'status'     => array('merged'), // maps to db 'wc-merged'
            'limit'      => $batch_size,
            'return'     => 'objects',
            'meta_query' => array(
                array(
                    'key'     => '_hostify_merged_into_order_id',
                    'compare' => 'EXISTS',
                ),
            ),
        ));

        if (empty($orders) || !is_array($orders)) {
            break;
        }

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            // Remove plugin meta and revert status to Pending.
            $order->delete_meta_data('_hostify_merged_into_order_id');
            $order->update_status('pending', 'Merge Orders plugin uninstalled: reverted from Merged to Pending.', false);
            $order->save();
        }
    } while (count($orders) === $batch_size);

    // Remove meta on merged (new) orders created by this plugin.
    do {
        $orders = wc_get_orders(array(
            'limit'      => $batch_size,
            'return'     => 'objects',
            'meta_query' => array(
                array(
                    'key'     => '_hostify_merged_from_order_ids',
                    'compare' => 'EXISTS',
                ),
            ),
        ));

        if (empty($orders) || !is_array($orders)) {
            break;
        }

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            $order->delete_meta_data('_hostify_merged_from_order_ids');
            $order->save();
        }
    } while (count($orders) === $batch_size);

    // Done.
    return;
}

/**
 * 2) Fallback path (WooCommerce not available): update CPT orders directly.
 *    This path may not cover HPOS installs where orders are not stored as posts,
 *    but it provides a best-effort cleanup when WC functions are not loaded.
 */
do {
    $order_ids = get_posts(array(
        'posts_per_page' => $batch_size,
        'post_type'      => 'shop_order',
        'post_status'    => 'wc-merged',
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => '_hostify_merged_into_order_id',
                'compare' => 'EXISTS',
            ),
        ),
    ));

    if (empty($order_ids) || !is_array($order_ids)) {
        break;
    }

    foreach ($order_ids as $order_id) {
        $order_id = (int) $order_id;

        delete_post_meta($order_id, '_hostify_merged_into_order_id');

        wp_update_post(array(
            'ID'          => $order_id,
            'post_status' => 'wc-pending',
        ));
    }
} while (count($order_ids) === $batch_size);

// Remove meta on merged (new) orders created by this plugin (best effort).
do {
    $order_ids = get_posts(array(
        'posts_per_page' => $batch_size,
        'post_type'      => 'shop_order',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => '_hostify_merged_from_order_ids',
                'compare' => 'EXISTS',
            ),
        ),
    ));

    if (empty($order_ids) || !is_array($order_ids)) {
        break;
    }

    foreach ($order_ids as $order_id) {
        $order_id = (int) $order_id;
        delete_post_meta($order_id, '_hostify_merged_from_order_ids');
    }
} while (count($order_ids) === $batch_size);
