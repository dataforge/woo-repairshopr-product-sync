# Woo RepairShopr Product Sync

## API Key Security and Encryption

This plugin encrypts your RepairShopr API key before storing it in the WordPress database to help protect it from casual snooping or direct database access.

### How Encryption Works

- The API key is encrypted using the AES-256-CBC algorithm via PHP's `openssl_encrypt` and decrypted with `openssl_decrypt`.
- By default, the plugin uses the WordPress `AUTH_KEY` constant (defined in your `wp-config.php`) as the encryption secret.
- If you define a custom secret constant `REPAIRSHOPR_SYNC_SECRET` in your `wp-config.php`, it will be used instead of `AUTH_KEY`.

**Example (optional, only if you want to override AUTH_KEY):**
```php
define('REPAIRSHOPR_SYNC_SECRET', 'your-strong-random-string');
```

### How It Works in Practice

- When you save your API key in the plugin settings, it is encrypted before being stored in the database.
- When the plugin needs to use the API key (for API requests or to display the masked value in the settings), it is decrypted in memory using the secret.
- The settings UI only ever shows the last 4 characters of the stored key for security.

### Security Considerations

- Using `AUTH_KEY` as the encryption secret means the key is unique per site and not stored in the database.
- If an attacker has access to both your files (including `wp-config.php`) and your database, they can decrypt the key. This is a limitation of all in-app encryption.
- This approach prevents casual snooping in the database and is a practical improvement over plaintext storage, but is not a substitute for full server security.
- For maximum security, consider using environment variables or external secrets management if your infrastructure supports it.

### References

- [WordPress Security Keys Documentation](https://wordpress.org/support/article/editing-wp-config-php/#security-keys)
- [PHP openssl_encrypt Manual](https://www.php.net/manual/en/function.openssl-encrypt.php)
- [Discussion: Storing Confidential Data in WordPress](https://felix-arntz.me/blog/storing-confidential-data-in-wordpress/)

---

## Example: Securely Storing and Retrieving an API Key in WordPress Plugins

You can use the following code pattern in your own plugins to securely store and retrieve API keys (or other secrets) in the WordPress database, using AES-256-CBC encryption and the site's `AUTH_KEY` (or a custom secret if you prefer).

```php
/**
 * Securely store an API key in the WordPress options table.
 * Uses AES-256-CBC encryption with AUTH_KEY or a custom secret.
 *
 * @param string $option_name The option key to store the encrypted value under.
 * @param string $api_key The plaintext API key to store.
 */
function save_encrypted_api_key($option_name, $api_key) {
    $secret = defined('REPAIRSHOPR_SYNC_SECRET') ? REPAIRSHOPR_SYNC_SECRET : (defined('AUTH_KEY') ? AUTH_KEY : '');
    if (!empty($secret)) {
        $encrypted = openssl_encrypt($api_key, 'AES-256-CBC', $secret, 0, substr(hash('sha256', $secret), 0, 16));
        update_option($option_name, $encrypted);
    } else {
        // Fallback: store plaintext (not recommended)
        update_option($option_name, $api_key);
    }
}

/**
 * Retrieve and decrypt an API key from the WordPress options table.
 * Uses AES-256-CBC decryption with AUTH_KEY or a custom secret.
 *
 * @param string $option_name The option key where the encrypted value is stored.
 * @return string|false The decrypted API key, or false if not found or decryption fails.
 */
function get_encrypted_api_key($option_name) {
    $secret = defined('REPAIRSHOPR_SYNC_SECRET') ? REPAIRSHOPR_SYNC_SECRET : (defined('AUTH_KEY') ? AUTH_KEY : '');
    $encrypted = get_option($option_name);
    if (!empty($secret) && !empty($encrypted)) {
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $secret, 0, substr(hash('sha256', $secret), 0, 16));
        return $decrypted !== false ? $decrypted : false;
    }
    return $encrypted; // fallback: plaintext (not recommended)
}

/**
 * Example usage:
 */
// Save a new API key
save_encrypted_api_key('my_plugin_api_key', 'your-api-key-here');

// Retrieve the API key for use
$api_key = get_encrypted_api_key('my_plugin_api_key');
if ($api_key) {
    // Use $api_key as needed
}
```

**Notes:**
- By default, this uses the WordPress `AUTH_KEY` as the encryption secret. You can define your own secret in `wp-config.php` if you want to isolate secrets between plugins:
  ```php
  define('REPAIRSHOPR_SYNC_SECRET', 'your-strong-random-string');
  ```
- This approach is suitable for most plugin-level secrets, but if you need maximum security, consider using environment variables or an external secrets manager.
- Always mask the API key in your plugin's UI (e.g., show only the last 4 characters).

Feel free to copy and adapt this code for your other RepairShopr-related plugins or any other WordPress plugin that needs to securely store secrets.
Synchronize your WooCommerce store's product data with RepairShopr, keeping quantities and retail prices in sync automatically.

## Overview

**Woo RepairShopr Product Sync** is a WordPress plugin that connects your WooCommerce store to RepairShopr, enabling automated synchronization of product quantities and retail prices. The plugin is designed to keep your inventory and pricing consistent between both platforms, reducing manual work and minimizing errors.

- **Syncs:** Product quantities and retail prices from RepairShopr to WooCommerce.
- **Does NOT sync:** Stock status changes (e.g., in-stock/out-of-stock flags).
- **Automated:** Uses WordPress scheduled tasks (cron) to run syncs at configurable intervals.
- **Rate Limiting:** Respects RepairShopr API rate limits to avoid overloading the API.

## Features

- One-way sync: RepairShopr is the master for product quantities and retail prices, and WooCommerce is updated to match RepairShopr.
- Excludes stock status changes to prevent unwanted status updates.
- Configurable sync interval (default: every 30 minutes).
- Option to enable or disable automatic syncing.
- Secure storage of RepairShopr API key in WordPress options.
- Admin interface for settings and manual sync.
- Handles API rate limits (default: 160 calls per minute, 5-minute cooldown if exceeded).

## How Products Are Matched

Products are matched between WooCommerce and RepairShopr using unique identifiers:
- **WooCommerce:** Products are identified by their SKU (Stock Keeping Unit).
- **RepairShopr:** Products are identified by their RepairShopr Item ID.

The plugin uses the WooCommerce SKU to find the corresponding RepairShopr item by its item ID, and updates the WooCommerce product's quantity and price to match the values from RepairShopr.

## How It Works

1. **Setup:**  
   - Install and activate the plugin.
   - Enter your RepairShopr API key in the plugin settings.
   - Configure sync interval and enable/disable automatic sync as needed.

2. **Sync Process:**  
   - The plugin schedules a cron job to run at the configured interval.
   - On each run, it fetches product data from both WooCommerce and RepairShopr.
   - It compares quantities and retail prices, updating WooCommerce as needed to match RepairShopr.
   - If the API rate limit is reached, the plugin waits for a cooldown period before retrying.

3. **Manual Sync:**  
   - You can trigger a manual sync from the WordPress admin interface.

4. **Settings:**  
   - All settings are available in the WordPress admin under the plugin's settings page.

## File Structure

- `woo-repairshopr-product-sync.php` — Main plugin file, handles setup, scheduling, and includes.
- `includes/settings.php` — Settings page and option management.
- `includes/api.php` — Handles API communication with RepairShopr.
- `includes/sync.php` — Core sync logic between WooCommerce and RepairShopr.
- `includes/admin.php` — Admin interface and manual sync controls.
- `assets/` — Static assets (if any).

## Requirements

- WordPress 5.0+
- WooCommerce
- A valid RepairShopr account and API key

## Installation

1. Upload the plugin files to your `/wp-content/plugins/` directory, or install via the WordPress plugin installer.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to the plugin settings page and enter your RepairShopr API key.
4. Configure sync options as desired.

## Support

For issues or feature requests, please open an issue on [GitHub](https://github.com/dataforge/woo-repairshopr-product-sync).

## License

This plugin is licensed under the [GNU General Public License v2.0 or later (GPL-2.0-or-later)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).  
You are free to use, modify, and distribute this plugin. No payment is required.

## Donations

If you find this plugin useful and would like to support its development, donations are welcome but completely optional.
