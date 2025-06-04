<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display the settings form
 */
function display_settings_form() {
    // Handle "Check for Plugin Updates" button
    $update_msg = '';
    if (isset($_POST['repairshopr_check_update']) && check_admin_referer('repairshopr_settings_nonce', 'repairshopr_settings_nonce')) {
        // Simulate the cron event for plugin update check
        do_action('wp_update_plugins');
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }
        // Remove the update_plugins transient to force a check
        delete_site_transient('update_plugins');
        // Call the update check directly as well
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }
        // Get update info
        $plugin_file = plugin_basename(dirname(__FILE__, 2) . '/woo-repairshopr-product-sync.php');
        $update_plugins = get_site_transient('update_plugins');
        if (isset($update_plugins->response) && isset($update_plugins->response[$plugin_file])) {
            $new_version = $update_plugins->response[$plugin_file]->new_version;
            $update_msg = '<div class="notice notice-success"><p>' . esc_html__('Update available: version ', 'repairshopr-sync') . esc_html($new_version) . '.</p></div>';
        } else {
            $update_msg = '<div class="notice notice-success"><p>' . esc_html__('No update available for this plugin.', 'repairshopr-sync') . '</p></div>';
        }
        echo $update_msg;
    }

    // Handle form submission
    if (isset($_POST['repairshopr_api_key']) && isset($_POST['repairshopr_api_url']) && isset($_POST['repairshopr_sync_auto_enabled']) && isset($_POST['repairshopr_sync_interval_minutes'])) {
        if (current_user_can('manage_options')) {
            if (check_admin_referer('repairshopr_settings_nonce')) {
                $stored_api_key = '';
                if (defined('REPAIRSHOPR_SYNC_SECRET') || defined('AUTH_KEY')) {
                    $encrypted = get_option(REPAIRSHOPR_SYNC_OPTION_KEY);
                    $secret = defined('REPAIRSHOPR_SYNC_SECRET') ? REPAIRSHOPR_SYNC_SECRET : (defined('AUTH_KEY') ? AUTH_KEY : '');
                    if (!empty($encrypted) && !empty($secret)) {
                        $stored_api_key = openssl_decrypt($encrypted, 'AES-256-CBC', $secret, 0, substr(hash('sha256', $secret), 0, 16));
                    }
                }
                $submitted_api_key = sanitize_text_field($_POST['repairshopr_api_key']);
                // If the submitted value is masked, do not update the stored key
                if (
                    !empty($stored_api_key) &&
                    $submitted_api_key === str_repeat('*', max(0, strlen($stored_api_key) - 4)) . substr($stored_api_key, -4)
                ) {
                    // Do not update the API key
                } else {
                    if (defined('REPAIRSHOPR_SYNC_SECRET') || defined('AUTH_KEY')) {
                        $secret = defined('REPAIRSHOPR_SYNC_SECRET') ? REPAIRSHOPR_SYNC_SECRET : (defined('AUTH_KEY') ? AUTH_KEY : '');
                        if (!empty($secret)) {
                            $encrypted = openssl_encrypt($submitted_api_key, 'AES-256-CBC', $secret, 0, substr(hash('sha256', $secret), 0, 16));
                            update_option(REPAIRSHOPR_SYNC_OPTION_KEY, $encrypted);
                        }
                    } else {
                        update_option(REPAIRSHOPR_SYNC_OPTION_KEY, $submitted_api_key);
                    }
                }

                $auto_enabled = ($_POST['repairshopr_sync_auto_enabled'] === '1') ? 1 : 0;
                update_option('repairshopr_sync_auto_enabled', $auto_enabled);

                $interval = intval($_POST['repairshopr_sync_interval_minutes']);
                if ($interval < 1) $interval = 1;
                update_option('repairshopr_sync_interval_minutes', $interval);

                // Save API URL
                $api_url = trim(esc_url_raw($_POST['repairshopr_api_url']));
                if (empty($api_url)) {
                    $api_url = 'https://your-subdomain.repairshopr.com/api/v1';
                }
                update_option('repairshopr_api_url', $api_url);

                echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully.', 'repairshopr-sync') . '</p></div>';
            }
        }
    }

    $api_key = '';
    if (defined('REPAIRSHOPR_SYNC_SECRET') || defined('AUTH_KEY')) {
        $encrypted = get_option(REPAIRSHOPR_SYNC_OPTION_KEY);
        $secret = defined('REPAIRSHOPR_SYNC_SECRET') ? REPAIRSHOPR_SYNC_SECRET : (defined('AUTH_KEY') ? AUTH_KEY : '');
        if (!empty($encrypted) && !empty($secret)) {
            $api_key = openssl_decrypt($encrypted, 'AES-256-CBC', $secret, 0, substr(hash('sha256', $secret), 0, 16));
        }
    } else {
        $api_key = get_option(REPAIRSHOPR_SYNC_OPTION_KEY);
    }
    $auto_enabled = get_option('repairshopr_sync_auto_enabled', 1);
    $interval = get_option('repairshopr_sync_interval_minutes', 30);

    // API URL
    $api_url = get_option('repairshopr_api_url', 'https://your-subdomain.repairshopr.com/api/v1');

    // Mask API key for display
    $masked_api_key = '';
    if (!empty($api_key)) {
        $masked_api_key = str_repeat('*', max(0, strlen($api_key) - 4)) . substr($api_key, -4);
    }

    ?>
    <h3><?php echo esc_html__('RepairShopr API Settings', 'repairshopr-sync'); ?></h3>
    <div style="color:red;font-weight:bold;">DEBUG: settings.php loaded (marker)</div>
    <form method="post">
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="repairshopr_api_key"><?php echo esc_html__('API Key', 'repairshopr-sync'); ?></label>
                </th>
                <td>
                    <input type="text" id="repairshopr_api_key" name="repairshopr_api_key" 
                           value="<?php echo esc_attr($masked_api_key); ?>" class="regular-text" autocomplete="off" />
                    <p class="description">
                        <?php echo esc_html__('Enter your RepairShopr API key here. You can find this in your RepairShopr account settings.', 'repairshopr-sync'); ?>
                        <?php if (!empty($api_key)) {
                            echo '<br>' . esc_html__('For security, only the last 4 characters of your stored API key are shown. Enter a new key to update.', 'repairshopr-sync');
                        } ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="repairshopr_api_url"><?php echo esc_html__('API URL', 'repairshopr-sync'); ?></label>
                </th>
                <td>
                    <input type="text" id="repairshopr_api_url" name="repairshopr_api_url"
                           value="<?php echo esc_attr($api_url); ?>" class="regular-text" autocomplete="off" />
                    <p class="description">
                        <?php echo esc_html__('Enter your RepairShopr API URL. Default:', 'repairshopr-sync'); ?>
                        <code>https://your-subdomain.repairshopr.com/api/v1</code>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <?php echo esc_html__('Automatic Sync', 'repairshopr-sync'); ?>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="repairshopr_sync_auto_enabled" name="repairshopr_sync_auto_enabled" value="1" <?php checked($auto_enabled, 1); ?> />
                        <?php echo esc_html__('On', 'repairshopr-sync'); ?>
                    </label>
                    <p class="description">
                        <?php echo esc_html__('Enable or disable automatic syncing on a schedule.', 'repairshopr-sync'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="repairshopr_sync_interval_minutes"><?php echo esc_html__('Auto sync interval (minutes)', 'repairshopr-sync'); ?></label>
                </th>
                <td>
                    <input type="number" min="1" id="repairshopr_sync_interval_minutes" name="repairshopr_sync_interval_minutes" value="<?php echo esc_attr($interval); ?>" <?php echo $auto_enabled ? '' : 'disabled'; ?> />
                    <p class="description">
                        <?php echo esc_html__('How often to run automatic sync (minimum 1 minute).', 'repairshopr-sync'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <?php wp_nonce_field('repairshopr_settings_nonce'); ?>
        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" 
                   value="<?php echo esc_attr__('Save Settings', 'repairshopr-sync'); ?>" />
        </p>
    </form>

    <form method="post" action="" style="margin-top:2em;">
        <?php wp_nonce_field('repairshopr_settings_nonce', 'repairshopr_settings_nonce'); ?>
        <input type="hidden" name="repairshopr_check_update" value="1">
        <?php submit_button(__('Check for Plugin Updates', 'repairshopr-sync'), 'secondary'); ?>
    </form>
    <script>
    // Enable/disable interval input based on checkbox
    document.addEventListener('DOMContentLoaded', function() {
        var autoCheckbox = document.getElementById('repairshopr_sync_auto_enabled');
        var intervalInput = document.getElementById('repairshopr_sync_interval_minutes');
        function toggleInterval() {
            intervalInput.disabled = !autoCheckbox.checked;
        }
        autoCheckbox.addEventListener('change', toggleInterval);
        toggleInterval();
    });
    </script>
    <?php
}
