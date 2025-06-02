# Woo RepairShopr Product Sync

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
