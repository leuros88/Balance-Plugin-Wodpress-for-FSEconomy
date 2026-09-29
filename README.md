<p align="center">
  <img src="assets/logobalance.jpeg" alt="FSE Balance Logo" width="200">
</p>

<h1 align="center">FSE Balance — WordPress Plugin for FSEconomy</h1>

<p align="center">
  Display any FSEconomy account or group bank balance with a simple shortcode.<br>
  Synced automatically every 30 minutes, served from cache — no API call per visit.
</p>

<p align="center">
  <a href="README.md"><strong>English</strong></a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.pt.md">Português</a>
</p>

<p align="center">
  <a href="https://github.com/leuros88/Balance-Plugin-Wodpress-for-FSEconomy/releases/latest"><img src="https://img.shields.io/github/v/release/leuros88/Balance-Plugin-Wodpress-for-FSEconomy?label=latest%20release" alt="Latest release"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/leuros88/Balance-Plugin-Wodpress-for-FSEconomy" alt="License: MIT"></a>
  <img src="https://img.shields.io/badge/WordPress-6.0%2B-blue" alt="WordPress 6.0+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4" alt="PHP 7.4+">
</p>

---

## Table of contents

- [Features](#features)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Updates](#updates)
- [Uninstall](#uninstall)
- [Security](#security)
- [License](#license)

## Features

- 📊 `[fse_balance]` shortcode rendering the cached balance as formatted currency (e.g. `$12,345.67`).
- ⏱️ Automatic background sync every 30 minutes via a custom WP-Cron interval.
- ⚡ Cached output — visitors never trigger a live API request.
- 🔒 Overlap lock to prevent concurrent fetches (cron + manual refresh).
- 🖥️ Modern admin page with logo, balance overview, sync status and health indicator.
- ⚙️ Settings for the API URL, one-click manual refresh and update checker.
- 🔄 Self-hosted updates from GitHub Releases (one-click + auto-updates support).
- 🧹 Clean uninstall: removes options, locks and scheduled events.

## How it works

1. You paste your FSEconomy API URL (including your API key) in `WP Admin > FSE Balance`.
2. Every 30 minutes, WP-Cron fetches the API, parses `Statistic > Bank_balance` from the XML response and stores it in the database.
3. The `[fse_balance]` shortcode prints the cached value wherever you place it.

> **Note:** WP-Cron runs on site visits. On low-traffic sites, set up a real system cron calling `wp-cron.php` every 30 minutes for reliable scheduling.

## Requirements

| Requirement | Version       |
|-------------|---------------|
| WordPress   | 6.0 or higher |
| PHP         | 7.4 or higher |
| FSEconomy   | A valid API URL with key |

## Installation

1. Go to [**Releases**](https://github.com/leuros88/Balance-Plugin-Wodpress-for-FSEconomy/releases) and download the latest **`fse-balance-plugin.zip`** (not the `Source code` archives).
2. Install it with one of these methods:
   - **Option A — WP Admin (recommended):** go to `Plugins > Add New > Upload Plugin`, upload the `.zip` and click **Activate**.
   - **Option B — FTP:** extract the zip and upload the `fse-balance-plugin/` folder to `wp-content/plugins/`, then activate it in `Plugins`.
3. Go to `FSE Balance` in the admin menu and paste your FSEconomy API URL into the **API URL** field, then click **Save URL**.

## Usage

Add the shortcode anywhere (post, page, widget, block):

```
[fse_balance]
```

It renders the last cached balance, e.g. `$12,345.67`.

To sync on demand, use the **Force balance refresh now** button on the settings page. It queries the API immediately and updates the cached value, last-update date and status.

## Updates

The plugin checks this repository for new versions **every 12 hours** via the GitHub Releases API.

- When a release exists, WordPress offers it in `Dashboard > Updates` and in `Plugins`, with one-click update and a changelog popup.
- Enable fully automatic installs with **Enable auto-updates** on the plugin row.
- Force an immediate check from `FSE Balance > Check for updates now`.

## Uninstall

Deactivating keeps your settings. Deleting the plugin removes everything: options, locks, scheduled events and cached update data.

## Security

- **SSRF protection:** only `http/https` URLs on `*.fseconomy.net` with standard ports (80/443) are accepted; credentials in URLs are rejected.
- **Safe XML parsing:** external entities disabled (XXE protection) with a 500 KB response size limit.
- **Hardened admin:** capability checks (`manage_options`) and nonces on every form and action.

## License

MIT License — created by **[Leuros88](https://github.com/leuros88)**.

See [LICENSE](LICENSE) for details.
