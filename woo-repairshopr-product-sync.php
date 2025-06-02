<?php
/**
 * Plugin Name:       Woo RepairShopr Product Sync
 * Plugin URI:        https://github.com/radialmonster/woo-repairshopr-product-sync
 * Description:       Synchronizes product data between WooCommerce and RepairShopr, including quantities and retail prices, excluding stock status changes.
 * Version:           1.0.1
 * Author:            Phil Hart
 * License:           GPL-2.0-or-later
 * Domain Path:       /languages
 * Text Domain:       woo-repairshopr-product-sync
 * GitHub Plugin URI: https://github.com/radialmonster/woo-repairshopr-product-sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('REPAIRSHOPR_SYNC_VERSION', '1.0.1');
define('REPAIRSHOPR_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('REPAIRSHOPR_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('REPAIRSHOPR_SYNC_API_CALLS_PER_MINUTE', 160);
define('REPAIRSHOPR_SYNC_RATE_LIMIT_SECONDS', 300);
define('REPAIRSHOPR_SYNC_OPTION_KEY', 'repairshopr_sync_api_key');

// Include required files
require_once REPAIRSHOPR_SYNC_PLUGIN_DIR . 'includes/settings.php';
require_once REPAIRSHOPR_SYNC_PLUGIN_DIR . 'includes/api.php';
require_once REPAIRSHOPR_SYNC_PLUGIN_DIR . 'includes/sync.php';
require_once REPAIRSHOPR_SYNC_PLUGIN_DIR . 'includes/admin.php';


// Register activation hook
register_activation_hook(__FILE__, 'repairshopr_sync_activation');
function repairshopr_sync_activation() {
    // Initialize options
    if (!get_option(REPAIRSHOPR_SYNC_OPTION_KEY)) {
        add_option(REPAIRSHOPR_SYNC_OPTION_KEY, '');
    }
    if (get_option('repairshopr_sync_auto_enabled') === false) {
        add_option('repairshopr_sync_auto_enabled', 1);
    }
    if (get_option('repairshopr_sync_interval_minutes') === false) {
        add_option('repairshopr_sync_interval_minutes', 30);
    }

    // Schedule cron if enabled
    $auto_enabled = get_option('repairshopr_sync_auto_enabled', 1);
    $interval = intval(get_option('repairshopr_sync_interval_minutes', 30));
    if ($interval < 1) $interval = 1;
    if ($auto_enabled) {
        $interval_key = 'repairshopr_sync_' . $interval . '_minutes';
        if (!wp_next_scheduled('repairshopr_sync_cron_hook')) {
            wp_schedule_event(time(), $interval_key, 'repairshopr_sync_cron_hook');
        }
    }
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'repairshopr_sync_deactivation');
function repairshopr_sync_deactivation() {
    $timestamp = wp_next_scheduled('repairshopr_sync_cron_hook');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'repairshopr_sync_cron_hook');
    }
}

// Add custom interval
add_filter('cron_schedules', 'repairshopr_add_cron_interval');
function repairshopr_add_cron_interval($schedules) {
    $interval = intval(get_option('repairshopr_sync_interval_minutes', 30));
    if ($interval < 1) $interval = 1;
    $interval_key = 'repairshopr_sync_' . $interval . '_minutes';
    $schedules[$interval_key] = array(
        'interval' => $interval * 60,
        'display'  => sprintf(esc_html__('Every %d Minutes', 'repairshopr-sync'), $interval),
    );
    return $schedules;
}

// Reschedule cron if settings change
add_action('update_option_repairshopr_sync_auto_enabled', 'repairshopr_reschedule_cron', 10, 2);
add_action('update_option_repairshopr_sync_interval_minutes', 'repairshopr_reschedule_cron', 10, 2);
function repairshopr_reschedule_cron($old_value, $value) {
    $timestamp = wp_next_scheduled('repairshopr_sync_cron_hook');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'repairshopr_sync_cron_hook');
    }
    $auto_enabled = get_option('repairshopr_sync_auto_enabled', 1);
    $interval = intval(get_option('repairshopr_sync_interval_minutes', 30));
    if ($interval < 1) $interval = 1;
    if ($auto_enabled) {
        $interval_key = 'repairshopr_sync_' . $interval . '_minutes';
        wp_schedule_event(time(), $interval_key, 'repairshopr_sync_cron_hook');
    }
}

// Add action for scheduled event
add_action('repairshopr_sync_cron_hook', 'sync_repairshopr_data_with_woocommerce');
