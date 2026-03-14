# GB Cache

**Server-Level static HTML cache with Admin UI for exclusions, WP-Cron handling, and Cache Warming.**

GB Cache is a lightweight and highly efficient WordPress caching plugin designed to deliver static HTML pages directly through server-level `.htaccess` rewrite rules. By bypassing PHP and the WordPress database entirely for cached pages, GB Cache significantly improves your website's load times and overall performance.

## Core Features

* **Server-Level Delivery:** Modifies your `.htaccess` file upon activation to serve cached HTML files directly, ensuring lightning-fast Response Times (TTFB).
* **Device-Specific Caching:** Differentiates cache delivery and generation by device type (`desktop`, `mobile`, and `ios`) to accommodate responsive and adaptive designs.
* **Admin Settings Interface:** Manage your exclusions easily via **Settings > GB Cache**:
  * Checkbox list to easily exclude specific WordPress pages from the cache.
  * Text area to exclude custom URI paths (e.g., `/cart/`, `/checkout/`, `/wp-json/`).
* **Smart Cache Invalidation:** Automatically clears the cache during critical WordPress events:
  * Creating, updating, or deleting posts/pages.
  * Posting, editing, or deleting comments.
  * Activating/deactivating plugins or switching themes.
  * Saving menus or Customizer changes.
* **One-Click Manual Clear:** Adds a convenient "Clear GB Cache" button directly to the WordPress Admin Bar.
* **Intelligent Bypassing:** Automatically bypasses the cache for logged-in users, AJAX requests (`DOING_AJAX`), WP-Cron (`DOING_CRON`), Search results, 404 pages, and Feeds.
* **Cache Preloader (Warming):** Automatically warms the cache (for both Mobile and Desktop user-agents) for the homepage, newest 10 pages, and newest 5 posts immediately after a cache purge.
* **Scheduled Maintenance:** Purges the cache automatically every 10 hours using WP-Cron.
* **Safe Installation/Uninstallation:** Backs up your existing `.htaccess` file during activation and safely restores the backup upon deactivation.

## Installation

1. Upload the `gb-cache` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. *Note: Upon activation, the plugin automatically creates essential rewrite rules inside your `.htaccess` file and sets up a protected cache directory at `/wp-content/cache/gb-cache`.*

## Configuration

Navigate to **Settings > GB Cache** in your WordPress admin dashboard.

1. **Exclude Specific Pages:** Scroll through the checklist of your site's Pages and check the ones that should never be cached.
2. **Exclude Custom URIs:** Add strings or parts of URLs (one per line) to uniformly exclude them from caching (e.g., `/cart/` or `/my-account/`). 

## Technical Details

* **Cache Storage Path:** `/wp-content/cache/gb-cache/{device_type}/{http_host}/{request_uri}/index.html`
* **`.htaccess` Delivery:** The plugin uses Apache's `mod_rewrite` to intercept requests. It serves the cache directly unless:
  * The request is a `POST` request.
  * The request has a query string.
  * The user has a designated WordPress login cookie (`wordpress_logged_in_`, etc.).
* **Version:** 1.8
* **Author:** Tyson
* **License:** GPLv2
