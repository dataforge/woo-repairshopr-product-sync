<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Synchronize all product data between RepairShopr and WooCommerce
 */
function sync_repairshopr_data_with_woocommerce() {
    // For AJAX batch processing, we'll have a separate function
    error_log('Sync started: ' . date('Y-m-d H:i:s'));
    
    // Get products in batches to improve performance
    $page = 1;
    $per_page = 50;
    $changes = [];
    
    // Extra debug: log initial stock for test SKUs before any updates
    $test_skus = ['9839769', '9839768'];
    foreach ($test_skus as $test_sku) {
        $test_id = wc_get_product_id_by_sku($test_sku);
        if ($test_id) {
            $test_product = wc_get_product($test_id);
            error_log("SYNC DEBUG: PRE-SYNC CHECK SKU $test_sku, ID $test_id, qty: " . $test_product->get_stock_quantity());
        }
    }

    do {
        $wc_products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'post_status' => ['publish', 'draft']
        ]);
        
        foreach ($wc_products as $product) {
            $product_obj = wc_get_product($product->ID);
            error_log("SYNC DEBUG: Processing product ID {$product->ID}, type: " . $product_obj->get_type() . ", SKU: " . $product_obj->get_sku());

            if ($product_obj->is_type('variable')) {
                // Log all variation SKUs and initial quantities before any updates
                $variation_ids = $product_obj->get_children();
                $variation_states = [];
                foreach ($variation_ids as $child_id) {
                    $variation = wc_get_product($child_id);
                    $variation_states[] = [
                        'id' => $child_id,
                        'sku' => $variation->get_sku(),
                        'qty' => $variation->get_stock_quantity()
                    ];
                }
                error_log("SYNC DEBUG: Initial variation states for parent ID {$product->ID}: " . print_r($variation_states, true));
                foreach ($variation_ids as $child_id) {
                    $variation = wc_get_product($child_id);
                    error_log("SYNC DEBUG: Processing variation ID $child_id, SKU: " . $variation->get_sku());
                    sync_product_data($variation, $changes);
                }
            } else {
                sync_product_data($product_obj, $changes);
            }
        }
        
        $page++;
    } while (count($wc_products) >= $per_page);

    set_transient('repairshopr_data_sync_changes', $changes, 3600);
    error_log('Sync completed: ' . date('Y-m-d H:i:s'));
}

/**
 * Get total number of products for batch processing
 * 
 * @return int Total number of products
 */
function get_total_products_count() {
    global $wpdb;
    
    // Count all products and variations
    $count_posts = wp_count_posts('product');
    $published_products = $count_posts->publish + $count_posts->draft;
    
    // Count variations
    $variation_count = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type = 'product_variation'
        AND post_status IN ('publish', 'draft')
    ");
    
    return $published_products + (int)$variation_count;
}

/**
 * Process a batch of products for AJAX sync
 * 
 * @param int $batch_number Current batch number
 * @param int $batch_size Size of each batch
 * @return array Result information
 */
function process_sync_batch($batch_number, $batch_size) {
    $offset = $batch_number * $batch_size;
    $changes = [];
    $products_processed = 0;
    
    // Get a batch of products
    $wc_products = get_posts([
        'post_type' => 'product',
        'posts_per_page' => $batch_size,
        'offset' => $offset,
        'post_status' => ['publish', 'draft']
    ]);
    
    foreach ($wc_products as $product) {
        $product_obj = wc_get_product($product->ID);
        $products_processed++;

        if ($product_obj->is_type('variable')) {
            foreach ($product_obj->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                sync_product_data($variation, $changes);
                $products_processed++;
            }
        } else {
            sync_product_data($product_obj, $changes);
        }
    }
    
    // Store changes in a transient with a batch-specific name
    if (!empty($changes)) {
        $existing_changes = get_transient('repairshopr_data_sync_changes') ?: [];
        $updated_changes = array_merge($existing_changes, $changes);
        set_transient('repairshopr_data_sync_changes', $updated_changes, 3600);
    }
    
    $more_products = count($wc_products) >= $batch_size;
    
    return [
        'processed' => $products_processed,
        'changes_count' => count($changes),
        'more' => $more_products,
        'next_batch' => $more_products ? $batch_number + 1 : null,
    ];
}

/**
 * Sync a specific product by SKU
 */
function sync_product_data_by_sku($sku) {
    // Attempt to find the product in WooCommerce by SKU
    $product_id = wc_get_product_id_by_sku($sku);
    if (!$product_id) {
        error_log("No product found in WooCommerce with SKU: $sku");
        return false;
    }

    $product_obj = wc_get_product($product_id);
    if (!$product_obj) {
        error_log("Failed to load product object for SKU: $sku");
        return false;
    }

    // Log the current quantity and price for the SKU
    $current_qty = $product_obj->get_stock_quantity();
    $current_price = $product_obj->get_price();
    
    $changes = []; // Prepare an array to collect changes (if any)
    sync_product_data($product_obj, $changes);

    // Return the changes
    return $changes;
}

/**
 * Sync the quantity and price for a single product
 */
function sync_product_data($product_obj, &$changes) {
    $sku = $product_obj->get_sku();
    $prod_id = $product_obj->get_id();
    $prod_type = $product_obj->get_type();
    if ($sku === '9839769' || $sku === '9839768') {
        error_log("SYNC DEBUG: Entering sync_product_data for ID $prod_id, type: $prod_type, SKU: $sku");
    }
    if (!$sku) {
        return;
    }

    // Log the initial WooCommerce value at the start of sync
    $initial_qty = $product_obj->get_stock_quantity();
    $initial_price = $product_obj->get_regular_price();
    if ($sku === '9839769' || $sku === '9839768') {
        error_log("SYNC DEBUG: SKU $sku initial WC qty: $initial_qty, price: $initial_price");
    }

    $repairshopr_product_data = get_repairshopr_product($sku);

    // Debug log for RepairShopr data
    if ($sku === '9839769' || $sku === '9839768') {
        error_log("SYNC DEBUG: SKU $sku RepairShopr data: " . print_r($repairshopr_product_data, true));
    }

    if (!$repairshopr_product_data || !isset($repairshopr_product_data['quantity'], $repairshopr_product_data['price_retail'])) {
        return;
    }

    $woocommerce_current_qty = $product_obj->get_stock_quantity();
    $woocommerce_current_price = $product_obj->get_regular_price();

    $repairshopr_new_qty = $repairshopr_product_data['quantity'];
    $repairshopr_new_price = $repairshopr_product_data['price_retail'];

    // Normalize and cast for strict comparison
    $wc_qty = is_null($woocommerce_current_qty) ? 0 : (int)$woocommerce_current_qty;
    $rs_qty = is_null($repairshopr_new_qty) ? 0 : (int)$repairshopr_new_qty;
    $wc_price = is_null($woocommerce_current_price) ? 0.0 : (float)$woocommerce_current_price;
    $rs_price = is_null($repairshopr_new_price) ? 0.0 : (float)$repairshopr_new_price;

    // Debug log for comparison
    if ($sku === '9839769' || $sku === '9839768') {
        error_log("SYNC DEBUG: SKU $sku WC qty: $wc_qty (" . gettype($wc_qty) . "), RS qty: $rs_qty (" . gettype($rs_qty) . ")");
        error_log("SYNC DEBUG: SKU $sku WC price: $wc_price (" . gettype($wc_price) . "), RS price: $rs_price (" . gettype($rs_price) . ")");
    }

    $qty_changed = $wc_qty !== $rs_qty;
    $price_changed = abs($wc_price - $rs_price) > 0.0001;

    // Apply updates if needed
    if ($qty_changed || $price_changed) {
        // Prepare change details
        $change_details = [
            'name' => $product_obj->get_name(),
            'sku' => $sku,
            'old_qty' => $woocommerce_current_qty,
            'new_qty' => $repairshopr_new_qty,
            'old_price' => $woocommerce_current_price,
            'new_price' => $repairshopr_new_price,
            'timestamp' => current_time('mysql'),
        ];

        $manage_stock = $product_obj->get_manage_stock();
        // Debug log for manage stock
        if ($sku === '9839769' || $sku === '9839768') {
            error_log("SYNC DEBUG: SKU $sku manage_stock: " . ($manage_stock ? 'true' : 'false') . " qty_changed: " . ($qty_changed ? 'true' : 'false'));
        }
        if ($manage_stock) {
            if ($qty_changed) {
                // Try using WooCommerce stock update function for reliability
                wc_update_product_stock($product_obj, $repairshopr_new_qty);
                $after_wc_update_qty = (int)wc_get_product($product_obj->get_id())->get_stock_quantity();
                if ($sku === '9839769' || $sku === '9839768') {
                    error_log("SYNC DEBUG: SKU $sku after wc_update_product_stock qty: $after_wc_update_qty");
                }
            }
        }

        if ($price_changed) {
            $product_obj->set_regular_price($repairshopr_new_price);
        }
        
        $product_obj->save();
        $changes[] = $change_details;

        // Extra debug: check for errors and product status
        $post_status = get_post_status($product_obj->get_id());
        $manage_stock = $product_obj->get_manage_stock();
        if ($sku === '9839769' || $sku === '9839768') {
            error_log("SYNC DEBUG: SKU $sku after save. Post status: $post_status, manage_stock: " . ($manage_stock ? 'true' : 'false'));
        }

        // Reload product to confirm persistence
        $reloaded_product = wc_get_product($product_obj->get_id());
        $reloaded_qty = (int)$reloaded_product->get_stock_quantity();
        $reloaded_price = (float)$reloaded_product->get_regular_price();

        // Debug log (can be removed after investigation)
        if ($sku === '9839769' || $sku === '9839768') {
            error_log("SYNC DEBUG: SKU $sku saved. Reloaded qty: $reloaded_qty, price: $reloaded_price");
        }

        // Log the change to a transient (keep for 7 days, max 500 entries)
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'product_name' => $product_obj->get_name(),
            'sku' => $sku,
            'old_qty' => $woocommerce_current_qty,
            'new_qty' => $repairshopr_new_qty,
            'old_price' => $woocommerce_current_price,
            'new_price' => $repairshopr_new_price,
            'reloaded_qty' => $reloaded_qty,
            'reloaded_price' => $reloaded_price,
        ];
        $logs = get_transient('repairshopr_sync_logs') ?: [];
        $logs[] = $log_entry;
        // Keep only the most recent 500 entries
        if (count($logs) > 500) {
            $logs = array_slice($logs, -500);
        }
        set_transient('repairshopr_sync_logs', $logs, 7 * DAY_IN_SECONDS);
    }
}
