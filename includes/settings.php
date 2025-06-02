<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display the settings form
 */
function display_settings_form() {
    // Handle form submission
    if (isset($_POST['repairshopr_api_key']) && isset($_POST['repairshopr_sync_auto_enabled']) && isset($_POST['repairshopr_sync_interval_minutes'])) {
        if (current_user_can('manage_options')) {
            if (check_admin_referer('repairshopr_settings_nonce')) {
                $api_key = sanitize_text_field($_POST['repairshopr_api_key']);
                update_option(REPAIRSHOPR_SYNC_OPTION_KEY, $api_key);

                $auto_enabled = ($_POST['repairshopr_sync_auto_enabled'] === '1') ? 1 : 0;
                update_option('repairshopr_sync_auto_enabled', $auto_enabled);

                $interval = intval($_POST['repairshopr_sync_interval_minutes']);
                if ($interval < 1) $interval = 1;
                update_option('repairshopr_sync_interval_minutes', $interval);

                echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully.', 'repairshopr-sync') . '</p></div>';
            }
        }
    }

    $api_key = get_option(REPAIRSHOPR_SYNC_OPTION_KEY);
    $auto_enabled = get_option('repairshopr_sync_auto_enabled', 1);
    $interval = get_option('repairshopr_sync_interval_minutes', 30);

    ?>
    <h3><?php echo esc_html__('RepairShopr API Settings', 'repairshopr-sync'); ?></h3>
    <form method="post">
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="repairshopr_api_key"><?php echo esc_html__('API Key', 'repairshopr-sync'); ?></label>
                </th>
                <td>
                    <input type="text" id="repairshopr_api_key" name="repairshopr_api_key" 
                           value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                    <p class="description">
                        <?php echo esc_html__('Enter your RepairShopr API key here. You can find this in your RepairShopr account settings.', 'repairshopr-sync'); ?>
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
