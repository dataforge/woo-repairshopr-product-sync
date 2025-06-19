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
    if (!$sku) {
        return;
    }

    // Log the initial WooCommerce value at the start of sync
    $initial_qty = $product_obj->get_stock_quantity();
    $initial_price = $product_obj->get_regular_price();

    $repairshopr_product_data = get_repairshopr_product($sku);

    if (!$repairshopr_product_data || !isset($repairshopr_product_data['quantity'], $repairshopr_product_data['price_retail'])) {
        return;
    }

    $woocommerce_current_qty = $product_obj->get_stock_quantity();
    $woocommerce_current_price = $product_obj->get_regular_price();

    $repairshopr_new_qty = $repairshopr_product_data['quantity'];
    $repairshopr_new_price = $repairshopr_product_data['price_retail'];

    // Enhanced logging for debugging decimal issue
    error_log("STOCK DEBUG: SKU {$sku} (ID: {$prod_id}, Type: {$prod_type}) - Starting sync");
    error_log("STOCK DEBUG: SKU {$sku} - Raw RepairShopr qty: " . var_export($repairshopr_new_qty, true) . " (type: " . gettype($repairshopr_new_qty) . ")");
    error_log("STOCK DEBUG: SKU {$sku} - Raw RepairShopr price: " . var_export($repairshopr_new_price, true) . " (type: " . gettype($repairshopr_new_price) . ")");
    error_log("STOCK DEBUG: SKU {$sku} - WC current qty: " . var_export($woocommerce_current_qty, true) . " (type: " . gettype($woocommerce_current_qty) . ")");
    error_log("STOCK DEBUG: SKU {$sku} - WC current price: " . var_export($woocommerce_current_price, true) . " (type: " . gettype($woocommerce_current_price) . ")");
    error_log("STOCK DEBUG: SKU {$sku} - _stock meta before: " . var_export(get_post_meta($prod_id, '_stock', true), true));

    // Clean RepairShopr data before using it - this is the key fix
    $rs_qty_clean = is_numeric($repairshopr_new_qty) ? (int)$repairshopr_new_qty : 0;
    $rs_price_clean = is_numeric($repairshopr_new_price) ? (float)$repairshopr_new_price : 0.0;

    error_log("STOCK DEBUG: SKU {$sku} - Cleaned RepairShopr qty: " . var_export($rs_qty_clean, true) . " (type: " . gettype($rs_qty_clean) . ")");
    error_log("STOCK DEBUG: SKU {$sku} - Cleaned RepairShopr price: " . var_export($rs_price_clean, true) . " (type: " . gettype($rs_price_clean) . ")");

    // Normalize and cast for strict comparison
    $wc_qty = is_null($woocommerce_current_qty) ? 0 : (int)$woocommerce_current_qty;
    $wc_price = is_null($woocommerce_current_price) ? 0.0 : (float)$woocommerce_current_price;

    error_log("STOCK DEBUG: SKU {$sku} - Final comparison - WC qty: {$wc_qty}, RS qty: {$rs_qty_clean}, WC price: {$wc_price}, RS price: {$rs_price_clean}");

    $qty_changed = $wc_qty !== $rs_qty_clean;
    $price_changed = abs($wc_price - $rs_price_clean) > 0.0001;

    error_log("STOCK DEBUG: SKU {$sku} - Qty changed: " . ($qty_changed ? 'YES' : 'NO') . ", Price changed: " . ($price_changed ? 'YES' : 'NO'));

    // Apply updates if needed
    if ($qty_changed || $price_changed) {
        // Prepare change details
        $change_details = [
            'name' => $product_obj->get_name(),
            'sku' => $sku,
            'old_qty' => $woocommerce_current_qty,
            'new_qty' => $rs_qty_clean,
            'old_price' => $woocommerce_current_price,
            'new_price' => $rs_price_clean,
            'timestamp' => current_time('mysql'),
        ];

        $manage_stock = $product_obj->get_manage_stock();
        error_log("STOCK DEBUG: SKU {$sku} - Manage stock enabled: " . ($manage_stock ? 'YES' : 'NO'));
        
        if ($manage_stock) {
            if ($qty_changed) {
                error_log("STOCK DEBUG: SKU {$sku} - Updating stock from {$wc_qty} to {$rs_qty_clean}");
                // Use WooCommerce stock update function with cleaned integer data
                wc_update_product_stock($product_obj, $rs_qty_clean);
                $after_wc_update_qty = (int)wc_get_product($product_obj->get_id())->get_stock_quantity();
                error_log("STOCK DEBUG: SKU {$sku} - After wc_update_product_stock, qty is: " . var_export($after_wc_update_qty, true));
            }
        }

        if ($price_changed) {
            error_log("STOCK DEBUG: SKU {$sku} - Updating price from {$wc_price} to {$rs_price_clean}");
            $product_obj->set_regular_price($rs_price_clean);
        }
        
        $product_obj->save();
        $changes[] = $change_details;

        // Extra debug: check for errors and product status
        $post_status = get_post_status($product_obj->get_id());
        $manage_stock = $product_obj->get_manage_stock();

        // Reload product to confirm persistence
        $reloaded_product = wc_get_product($product_obj->get_id());
        $reloaded_qty = (int)$reloaded_product->get_stock_quantity();
        $reloaded_price = (float)$reloaded_product->get_regular_price();

        error_log("STOCK DEBUG: SKU {$sku} - After save - _stock meta: " . var_export(get_post_meta($prod_id, '_stock', true), true));
        error_log("STOCK DEBUG: SKU {$sku} - Reloaded qty: " . var_export($reloaded_qty, true) . " (type: " . gettype($reloaded_qty) . ")");
        error_log("STOCK DEBUG: SKU {$sku} - Reloaded price: " . var_export($reloaded_price, true) . " (type: " . gettype($reloaded_price) . ")");

        // Log the change to a transient (keep for 7 days, max 500 entries)
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'product_name' => $product_obj->get_name(),
            'sku' => $sku,
            'old_qty' => $woocommerce_current_qty,
            'new_qty' => $rs_qty_clean,
            'old_price' => $woocommerce_current_price,
            'new_price' => $rs_price_clean,
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
