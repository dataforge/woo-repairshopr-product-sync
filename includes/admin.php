<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Create a submenu under WooCommerce for the RepairShopr sync feature
add_action('admin_menu', function() {
    add_submenu_page('woocommerce', 'Woo RepairShopr Product Sync', 'Woo RepairShopr Product Sync', 'manage_options', 'repairshopr-sync', 'repairshopr_sync_page_callback');
});

// Enqueue necessary scripts for the sync page
add_action('admin_enqueue_scripts', function($hook) {
    if ('woocommerce_page_repairshopr-sync' !== $hook) {
        return;
    }
    
    wp_enqueue_script('repairshopr-sync-script', REPAIRSHOPR_SYNC_PLUGIN_URL . 'assets/js/sync-script.js', array('jquery'), REPAIRSHOPR_SYNC_VERSION, true);
    wp_localize_script('repairshopr-sync-script', 'repairshopr_sync', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('repairshopr_sync_nonce'),
        'batch_size' => 10, // Process 10 products per batch
        'processing_text' => __('Processing... Please do not close this page.', 'repairshopr-sync'),
        'complete_text' => __('Synchronization complete!', 'repairshopr-sync')
    ));
    
    // Add CSS for the progress bar
    wp_add_inline_style('admin-bar', '
        .repairshopr-progress-container {
            width: 100%;
            background-color: #f1f1f1;
            margin: 10px 0;
            height: 30px;
            border-radius: 5px;
        }
        .repairshopr-progress-bar {
            width: 0%;
            height: 30px;
            background-color: #4CAF50;
            text-align: center;
            line-height: 30px;
            color: white;
            border-radius: 5px;
            transition: width 0.3s;
        }
        .repairshopr-status {
            margin-bottom: 20px;
            font-weight: bold;
        }
    ');
});

// Handle the manual sync request
add_action('admin_post_sync_repairshopr_data', function() {
    // Security check
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'repairshopr-sync'));
    }
    
    // Verify nonce
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'repairshopr_sync_nonce')) {
        wp_die(__('Security check failed. Please try again.', 'repairshopr-sync'));
    }
    
    set_time_limit(0);
    sync_repairshopr_data_with_woocommerce();
    wp_redirect(admin_url('admin.php?page=repairshopr-sync&sync=complete'));
    exit;
});


// Handle category-specific sync
add_action('admin_post_sync_repairshopr_category', function() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'repairshopr-sync'));
    }
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'repairshopr_sync_nonce')) {
        wp_die(__('Security check failed. Please try again.', 'repairshopr-sync'));
    }
    if (empty($_POST['category_id'])) {
        wp_redirect(admin_url('admin.php?page=repairshopr-sync&error=empty_category'));
        exit;
    }
    $category_id = intval($_POST['category_id']);
    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => ['publish', 'draft'],
        'tax_query' => [
            [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => [$category_id],
                'include_children' => true,
                'operator' => 'IN',
            ],
        ],
        'fields' => 'ids',
    ];
    $product_ids = get_posts($args);
    $changes = [];
    foreach ($product_ids as $product_id) {
        $product_obj = wc_get_product($product_id);
        if ($product_obj->is_type('variable')) {
            foreach ($product_obj->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                sync_product_data($variation, $changes);
            }
        } else {
            sync_product_data($product_obj, $changes);
        }
    }
    if (!empty($changes)) {
        set_transient('repairshopr_data_sync_changes', $changes, 3600);
        wp_redirect(admin_url('admin.php?page=repairshopr-sync&sync=category_complete'));
    } else {
        wp_redirect(admin_url('admin.php?page=repairshopr-sync&notice=no_category_changes'));
    }
    exit;
});

// Handle specific SKU sync
add_action('admin_post_sync_specific_sku', function() {
    // Security check
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'repairshopr-sync'));
    }
    
    // Verify nonce
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'repairshopr_sync_nonce')) {
        wp_die(__('Security check failed. Please try again.', 'repairshopr-sync'));
    }
    
    // Ensure there's a SKU to sync
    if (empty($_POST['specific_sku'])) {
        wp_redirect(admin_url('admin.php?page=repairshopr-sync&error=empty_sku'));
        exit;
    }

    $sku = sanitize_text_field($_POST['specific_sku']);
    $changes = sync_product_data_by_sku($sku);
    
    if ($changes) {
        set_transient('repairshopr_specific_sku_sync', $changes, 3600);
        wp_redirect(admin_url('admin.php?page=repairshopr-sync&success=sku_synced'));
    } else {
        wp_redirect(admin_url('admin.php?page=repairshopr-sync&notice=no_changes'));
    }
    exit;
});

// Add AJAX handler for batch processing
add_action('wp_ajax_repairshopr_process_batch', 'handle_batch_processing');

function handle_batch_processing() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }

    // Verify nonce
    if (!check_ajax_referer('repairshopr_sync_nonce', 'nonce', false)) {
        wp_send_json_error('Invalid security token');
    }

    // Get batch parameters
    $batch_number = isset($_POST['batch']) ? intval($_POST['batch']) : 0;
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;
    
    // Process the batch
    $result = process_sync_batch($batch_number, $batch_size);
    
    if ($batch_number === 0) {
        // First batch - get total count for progress calculation
        $result['total'] = get_total_products_count();
    }
    
    wp_send_json_success($result);
}

// Display the sync page and any changes
function repairshopr_sync_page_callback() {
    // Check for API key first
    if (empty(get_option(REPAIRSHOPR_SYNC_OPTION_KEY))) {
        ?>
        <div class="wrap">
        <h1><?php echo esc_html__('Woo RepairShopr Product Sync', 'repairshopr-sync'); ?></h1>
            <div class="notice notice-error">
                <p><?php echo esc_html__('RepairShopr API key is not configured. Please set it in the settings tab.', 'repairshopr-sync'); ?></p>
            </div>
            <?php display_settings_form(); ?>
        </div>
        <?php
        return;
    }
    
    // Display tabs
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'sync';
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('RepairShopr Product Sync', 'repairshopr-sync'); ?></h1>
        
        <nav class="nav-tab-wrapper">
            <a href="?page=repairshopr-sync&tab=sync" class="nav-tab <?php echo $current_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html__('Sync', 'repairshopr-sync'); ?>
            </a>
            <a href="?page=repairshopr-sync&tab=settings" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html__('Settings', 'repairshopr-sync'); ?>
            </a>
            <a href="?page=repairshopr-sync&tab=logs" class="nav-tab <?php echo $current_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html__('Logs', 'repairshopr-sync'); ?>
            </a>
        </nav>
        
        <div class="tab-content">
            <?php
            switch ($current_tab) {
                case 'settings':
                    display_settings_form();
                    break;
                case 'logs':
                    display_logs_tab();
                    break;
                default:
                    display_sync_tab();
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}

function display_sync_tab() {
    // Display specific SKU sync results if available
    $specific_sku_changes = get_transient('repairshopr_specific_sku_sync');
    if ($specific_sku_changes) {
        echo '<div class="notice notice-success"><p>' . esc_html__('SKU successfully synced!', 'repairshopr-sync') . '</p></div>';
        display_changes_table($specific_sku_changes);
        delete_transient('repairshopr_specific_sku_sync');
    }
    
    // Display bulk sync results if available
    $changes = get_transient('repairshopr_data_sync_changes');
    if (!empty($changes)) {
        echo '<p>' . esc_html__('Changes applied:', 'repairshopr-sync') . '</p>';
        display_changes_table($changes);
        delete_transient('repairshopr_data_sync_changes');
    }
    
    // Progress container for AJAX-based sync
    echo '<div id="repairshopr-ajax-sync-container" style="margin: 20px 0;">';
    echo '<div class="repairshopr-status" id="repairshopr-sync-status"></div>';
    echo '<div class="repairshopr-progress-container" style="display: none;">';
    echo '<div class="repairshopr-progress-bar" id="repairshopr-sync-progress">0%</div>';
    echo '</div>';
    echo '</div>';
    
    // Button to start AJAX-based sync
    echo '<button id="start-ajax-sync" class="button button-primary">' . esc_html__('Start Sync Now for All', 'repairshopr-sync') . '</button>';

    // Category-specific sync buttons
    $categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    if (!empty($categories) && !is_wp_error($categories)) {
        echo '<h3>' . esc_html__('Sync by Category', 'repairshopr-sync') . '</h3>';
        foreach ($categories as $cat) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block; margin:0 10px 10px 0;">';
            echo '<input type="hidden" name="action" value="sync_repairshopr_category">';
            echo '<input type="hidden" name="category_id" value="' . esc_attr($cat->term_id) . '">';
            wp_nonce_field('repairshopr_sync_nonce');
            echo '<button type="submit" class="button button-secondary">' . esc_html__('Start Manual Sync for ', 'repairshopr-sync') . esc_html($cat->name) . '</button>';
            echo '</form>';
        }
    }

    // Form for syncing specific SKU
    echo '<h3>' . esc_html__('Sync Specific SKU', 'repairshopr-sync') . '</h3>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="sync_specific_sku">';
    wp_nonce_field('repairshopr_sync_nonce');
    echo esc_html__('SKU:', 'repairshopr-sync') . ' <input type="text" name="specific_sku" required />';
    echo '<input type="submit" value="' . esc_attr__('Sync SKU', 'repairshopr-sync') . '" class="button button-secondary">';
    echo '</form>';
    
    // Sync description box at the bottom
    echo '<div class="notice notice-info" style="margin: 40px 0 20px 0; padding: 15px; border-left: 4px solid #00a0d2;">';
    echo '<h3 style="margin-top: 0; color: #23282d;">' . esc_html__('What This Plugin Syncs', 'repairshopr-sync') . '</h3>';
    echo '<p style="margin-bottom: 10px;"><strong>' . esc_html__('Data Synchronized:', 'repairshopr-sync') . '</strong></p>';
    echo '<ul style="margin-left: 20px; margin-bottom: 15px;">';
    echo '<li>' . esc_html__('Product Quantity (stock levels)', 'repairshopr-sync') . '</li>';
    echo '<li>' . esc_html__('Product Price (retail price)', 'repairshopr-sync') . '</li>';
    echo '</ul>';
    echo '<p style="margin-bottom: 10px;"><strong>' . esc_html__('Sync Direction:', 'repairshopr-sync') . '</strong> ' . esc_html__('RepairShopr → WooCommerce (RepairShopr is the master system)', 'repairshopr-sync') . '</p>';
    echo '<p style="margin-bottom: 10px;"><strong>' . esc_html__('Product Matching:', 'repairshopr-sync') . '</strong> ' . esc_html__('RepairShopr Product ID matches WooCommerce Product SKU', 'repairshopr-sync') . '</p>';
    echo '<p style="margin-bottom: 0; font-style: italic; color: #666;">' . esc_html__('Note: This plugin does not sync product names, descriptions, categories, images, or other attributes - only quantity and price data.', 'repairshopr-sync') . '</p>';
    echo '</div>';
}

function display_changes_table($changes) {
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Date/Time', 'repairshopr-sync') . '</th>';
    echo '<th>' . esc_html__('Name', 'repairshopr-sync') . '</th>';
    echo '<th>' . esc_html__('SKU', 'repairshopr-sync') . '</th>';
    echo '<th>' . esc_html__('Old Qty', 'repairshopr-sync') . '</th>';
    echo '<th>' . esc_html__('New Qty', 'repairshopr-sync') . '</th>';
    echo '<th>' . esc_html__('Old Price', 'repairshopr-sync') . '</th>';
    echo '<th>' . esc_html__('New Price', 'repairshopr-sync') . '</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    foreach ($changes as $change) {
        $qty_changed = $change['old_qty'] !== $change['new_qty'];
        $price_changed = $change['old_price'] !== $change['new_price'];
        $highlightClass = ($qty_changed || $price_changed) ? 'style="background-color: #fffdcd;"' : '';
        $timestamp = isset($change['timestamp']) ? esc_html($change['timestamp']) : 'N/A';

        echo '<tr ' . $highlightClass . '>';
        echo '<td>' . $timestamp . '</td>';
        echo '<td>' . esc_html($change['name']) . '</td>';
        echo '<td>' . esc_html($change['sku']) . '</td>';
        echo '<td>' . esc_html($change['old_qty']) . '</td>';
        echo '<td>' . esc_html($change['new_qty']) . '</td>';
        echo '<td>' . wc_price($change['old_price']) . '</td>';
        echo '<td>' . wc_price($change['new_price']) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
}

function display_logs_tab() {
    // Handle clear logs action
    if (isset($_POST['clear_repairshopr_logs']) && check_admin_referer('clear_repairshopr_logs')) {
        delete_transient('repairshopr_sync_logs');
        echo '<div class="notice notice-success"><p>' . esc_html__('Logs cleared.', 'repairshopr-sync') . '</p></div>';
    }

    $logs = get_transient('repairshopr_sync_logs');
    echo '<h3>' . esc_html__('Recent Product Changes (last 7 days)', 'repairshopr-sync') . '</h3>';

    if (empty($logs)) {
        echo '<p>' . esc_html__('No product changes have been logged in the last 7 days.', 'repairshopr-sync') . '</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date/Time', 'repairshopr-sync') . '</th>';
        echo '<th>' . esc_html__('Product Name', 'repairshopr-sync') . '</th>';
        echo '<th>' . esc_html__('SKU', 'repairshopr-sync') . '</th>';
        echo '<th>' . esc_html__('Old Qty', 'repairshopr-sync') . '</th>';
        echo '<th>' . esc_html__('New Qty', 'repairshopr-sync') . '</th>';
        echo '<th>' . esc_html__('Old Price', 'repairshopr-sync') . '</th>';
        echo '<th>' . esc_html__('New Price', 'repairshopr-sync') . '</th>';
        echo '</tr></thead><tbody>';
        foreach (array_reverse($logs) as $log) {
            echo '<tr>';
            echo '<td>' . esc_html($log['timestamp']) . '</td>';
            echo '<td>' . esc_html($log['product_name']) . '</td>';
            echo '<td>' . esc_html($log['sku']) . '</td>';
            echo '<td>' . esc_html($log['old_qty']) . '</td>';
            echo '<td>' . esc_html($log['new_qty']) . '</td>';
            echo '<td>' . wc_price($log['old_price']) . '</td>';
            echo '<td>' . wc_price($log['new_price']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // Handle cron job removal
    if (isset($_POST['remove_cron_job']) && check_admin_referer('remove_cron_job_nonce')) {
        $timestamp = isset($_POST['cron_timestamp']) ? intval($_POST['cron_timestamp']) : 0;
        $hook = isset($_POST['cron_hook']) ? sanitize_text_field($_POST['cron_hook']) : '';
        $args = isset($_POST['cron_args']) ? json_decode(stripslashes($_POST['cron_args']), true) : [];
        if ($timestamp && $hook) {
            wp_unschedule_event($timestamp, $hook, is_array($args) ? $args : []);
            echo '<div class="notice notice-success"><p>' . esc_html__('Cron job removed: ', 'repairshopr-sync') . esc_html($hook) . '</p></div>';
        }
    }

    // Show all scheduled cron jobs
    echo '<h3 style="margin-top:40px;">' . esc_html__('Scheduled Cron Jobs', 'repairshopr-sync') . '</h3>';
    if (function_exists('_get_cron_array')) {
        $crons = _get_cron_array();
        if (empty($crons)) {
            echo '<p>' . esc_html__('No scheduled cron jobs found.', 'repairshopr-sync') . '</p>';
        } else {
            // Collect all jobs into a sortable array
            $cron_jobs = [];
            foreach ($crons as $timestamp => $cronhooks) {
                foreach ($cronhooks as $hook => $events) {
                    foreach ($events as $event) {
                        $cron_jobs[] = [
                            'timestamp' => $timestamp,
                            'hook' => $hook,
                            'args' => $event['args'],
                        ];
                    }
                }
            }
            // Sort by hook name, then by next run time
            usort($cron_jobs, function($a, $b) {
                $hook_cmp = strcmp($a['hook'], $b['hook']);
                if ($hook_cmp !== 0) return $hook_cmp;
                return $a['timestamp'] <=> $b['timestamp'];
            });

            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Next Run (UTC)', 'repairshopr-sync') . '</th>';
            echo '<th>' . esc_html__('Hook Name', 'repairshopr-sync') . '</th>';
            echo '<th>' . esc_html__('Arguments', 'repairshopr-sync') . '</th>';
            echo '<th>' . esc_html__('Action', 'repairshopr-sync') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($cron_jobs as $job) {
                echo '<tr>';
                echo '<td>' . esc_html(gmdate('Y-m-d H:i:s', $job['timestamp'])) . '</td>';
                echo '<td>' . esc_html($job['hook']) . '</td>';
                echo '<td>' . (!empty($job['args']) ? esc_html(json_encode($job['args'])) : '-') . '</td>';
                echo '<td>';
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field('remove_cron_job_nonce');
                echo '<input type="hidden" name="cron_timestamp" value="' . esc_attr($job['timestamp']) . '">';
                echo '<input type="hidden" name="cron_hook" value="' . esc_attr($job['hook']) . '">';
                echo '<input type="hidden" name="cron_args" value="' . esc_attr(json_encode($job['args'])) . '">';
                echo '<input type="submit" name="remove_cron_job" class="button button-small" value="' . esc_attr__('Remove', 'repairshopr-sync') . '">';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    } else {
        echo '<p>' . esc_html__('Could not retrieve cron jobs (function _get_cron_array not available).', 'repairshopr-sync') . '</p>';
    }

    // Test RepairShopr API section
    echo '<h3 style="margin-top:40px;">' . esc_html__('Test RepairShopr API', 'repairshopr-sync') . '</h3>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('test_repairshopr_api_nonce');
    echo '<label for="test_repairshopr_sku">' . esc_html__('Enter SKU to test:', 'repairshopr-sync') . '</label> ';
    echo '<input type="text" id="test_repairshopr_sku" name="test_repairshopr_sku" value="' . (isset($_POST['test_repairshopr_sku']) ? esc_attr($_POST['test_repairshopr_sku']) : '') . '" />';
    echo '<input type="submit" name="test_repairshopr_api" class="button button-secondary" value="' . esc_attr__('Test API', 'repairshopr-sync') . '">';
    echo '</form>';

    if (isset($_POST['test_repairshopr_api']) && check_admin_referer('test_repairshopr_api_nonce')) {
        $test_sku = sanitize_text_field($_POST['test_repairshopr_sku']);
        if ($test_sku) {
            require_once __DIR__ . '/api.php';
            $result = get_repairshopr_product($test_sku);
            echo '<div style="background:#f8f8f8; border:1px solid #ccc; padding:10px; margin-bottom:20px;">';
            echo '<strong>' . esc_html__('RepairShopr API response for SKU:', 'repairshopr-sync') . ' ' . esc_html($test_sku) . '</strong><br>';
            if ($result === false) {
                echo '<span style="color:red;">' . esc_html__('No data returned or error from API.', 'repairshopr-sync') . '</span>';
            } else {
                echo '<pre style="white-space:pre-wrap; word-break:break-all;">' . esc_html(print_r($result, true)) . '</pre>';
            }
            echo '</div>';
        }
    }

    // WooCommerce stock lookup section
    echo '<h3 style="margin-top:40px;">' . esc_html__('WooCommerce Stock Lookup', 'repairshopr-sync') . '</h3>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('test_wc_stock_lookup_nonce');
    echo '<label for="test_wc_stock_sku">' . esc_html__('Enter SKU to lookup:', 'repairshopr-sync') . '</label> ';
    echo '<input type="text" id="test_wc_stock_sku" name="test_wc_stock_sku" value="' . (isset($_POST['test_wc_stock_sku']) ? esc_attr($_POST['test_wc_stock_sku']) : '') . '" />';
    echo '<input type="submit" name="test_wc_stock_lookup" class="button button-secondary" value="' . esc_attr__('Lookup Stock', 'repairshopr-sync') . '">';
    echo '</form>';

    if (isset($_POST['test_wc_stock_lookup']) && check_admin_referer('test_wc_stock_lookup_nonce')) {
        $test_sku = sanitize_text_field($_POST['test_wc_stock_sku']);
        if ($test_sku) {
            $prod_id = wc_get_product_id_by_sku($test_sku);
            if ($prod_id) {
                $product = wc_get_product($prod_id);
                echo '<div style="background:#f8f8f8; border:1px solid #ccc; padding:10px; margin-bottom:20px;">';
                echo '<strong>' . esc_html__('WooCommerce stock for SKU:', 'repairshopr-sync') . ' ' . esc_html($test_sku) . '</strong><br>';
                echo esc_html__('Product/Variation ID:', 'repairshopr-sync') . ' ' . esc_html($prod_id) . '<br>';
                echo esc_html__('Type:', 'repairshopr-sync') . ' ' . esc_html($product->get_type()) . '<br>';
                echo esc_html__('Name:', 'repairshopr-sync') . ' ' . esc_html($product->get_name()) . '<br>';
                echo esc_html__('Stock Quantity:', 'repairshopr-sync') . ' ' . esc_html($product->get_stock_quantity()) . '<br>';
                echo esc_html__('Stock Status:', 'repairshopr-sync') . ' ' . esc_html($product->get_stock_status()) . '<br>';
                echo '</div>';
            } else {
                echo '<div style="color:red; margin-bottom:20px;">' . esc_html__('No WooCommerce product or variation found for this SKU.', 'repairshopr-sync') . '</div>';
            }
        }
    }

    // Clear logs form
    echo '<form method="post" style="margin-top:20px;">';
    wp_nonce_field('clear_repairshopr_logs');
    echo '<input type="submit" name="clear_repairshopr_logs" class="button button-secondary" value="' . esc_attr__('Clear Logs', 'repairshopr-sync') . '">';
    echo '</form>';
}
