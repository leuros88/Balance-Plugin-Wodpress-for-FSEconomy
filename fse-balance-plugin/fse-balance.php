<?php
/**
 * FSE Balance — WordPress Plugin.
 *
 * Displays the FSEconomy bank balance using shortcodes.
 * Created and maintained by Leuros88.
 * Repository: https://github.com/leuros88/Balance-Plugin-Wodpress-for-FSEconomy
 *
 * MIT License
 *
 * Copyright (c) Leuros88
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @package FSE_Balance
 *
 * Plugin Name: FSE Balance
 * Plugin URI: https://github.com/leuros88/Balance-Plugin-Wodpress-for-FSEconomy
 * Description: Displays any FSEconomy account or group bank balance with the [fse_balance] shortcode. It fetches Bank_balance from the FSEconomy API every 30 minutes via WP-Cron and serves the cached value. By Leuros88.
 * Version: 1.2
 * Author: Leuros88
 * Author URI: https://github.com/leuros88
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: fse-balance
 * Update URI: https://github.com/leuros88/Balance-Plugin-Wodpress-for-FSEconomy
 */

// ============================================================================
// TABLE OF CONTENTS
// ----------------------------------------------------------------------------
// 0. Security guard & constants
// 1. Cron schedule (30-minute interval)
// 2. Activation / deactivation / uninstall
// 3. URL validation (SSRF protection)
// 4. API fetcher (cached, run by cron)
// 5. Shortcode [fse_balance]
// 6. Admin settings page
// 7. Auto-update from GitHub Releases
// 8. Hook registration (bootstrap)
// ============================================================================

// ----------------------------------------------------------------------------
// 0. Security guard & constants
// ----------------------------------------------------------------------------

defined('ABSPATH') || exit;

define('FSE_BALANCE_CRON_HOOK', 'fse_balance_cron_event');
define('FSE_BALANCE_INTERVAL', 'every_thirty_minutes');
define('FSE_BALANCE_LOCK', 'fse_balance_fetch_lock');
define('FSE_BALANCE_MAX_BODY_SIZE', 500000); // 500 KB max XML response
define('FSE_BALANCE_VERSION', '1.2');
define('FSE_BALANCE_GITHUB_REPO', 'leuros88/Balance-Plugin-Wodpress-for-FSEconomy');
define('FSE_BALANCE_GITHUB_CACHE_KEY', 'fse_balance_github_release');

// ----------------------------------------------------------------------------
// 1. Cron schedule — Adds a 30-minute interval to WP-Cron
// ----------------------------------------------------------------------------

/**
 * Register a custom 30-minute cron interval.
 *
 * @param array $schedules Existing WP-Cron schedules.
 * @return array
 */
function fse_balance_add_cron_interval($schedules) {
    if (!isset($schedules[FSE_BALANCE_INTERVAL])) {
        $schedules[FSE_BALANCE_INTERVAL] = [
            'interval' => 1800,
            'display'  => 'Every 30 minutes',
        ];
    }
    return $schedules;
}

// ----------------------------------------------------------------------------
// 2. Activation / deactivation / uninstall
// ----------------------------------------------------------------------------

/**
 * Schedule the periodic fetch event (idempotent).
 * Called on activation and on every init as a self-healing check
 * in case WP-Cron lost the event.
 */
function fse_balance_schedule_event() {
    if (!wp_next_scheduled(FSE_BALANCE_CRON_HOOK)) {
        wp_schedule_event(time(), FSE_BALANCE_INTERVAL, FSE_BALANCE_CRON_HOOK);
    }
}

/**
 * Clean up scheduled events and locks on deactivation.
 */
function fse_balance_on_deactivation() {
    wp_clear_scheduled_hook(FSE_BALANCE_CRON_HOOK);
    delete_transient(FSE_BALANCE_LOCK);
}

/**
 * Remove all plugin data on uninstall.
 */
function fse_balance_uninstall() {
    wp_clear_scheduled_hook(FSE_BALANCE_CRON_HOOK);
    delete_option('fse_balance_api_url');
    delete_option('fse_balance_value');
    delete_option('fse_balance_last_update');
    delete_option('fse_balance_last_error');
    delete_transient(FSE_BALANCE_LOCK);
    delete_site_transient(FSE_BALANCE_GITHUB_CACHE_KEY);
}

// ----------------------------------------------------------------------------
// 3. URL validation (SSRF protection)
// ----------------------------------------------------------------------------

/**
 * Check that a given URL points to an official FSEconomy host.
 *
 * Only allows http/https to *.fseconomy.net, without credentials
 * and without non-standard ports.
 *
 * @param string $url URL to validate.
 * @return bool
 */
function fse_balance_is_allowed_url($url) {
    $parts = wp_parse_url($url);

    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }

    $host = strtolower($parts['host']);
    if ($host !== 'fseconomy.net' && $host !== 'server.fseconomy.net' && substr($host, -14) !== '.fseconomy.net') {
        return false;
    }

    if (isset($parts['port']) && (int) $parts['port'] !== 80 && (int) $parts['port'] !== 443) {
        return false;
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    return true;
}

// ----------------------------------------------------------------------------
// 4. API fetcher — Queries FSEconomy, parses Bank_balance, caches result
// ----------------------------------------------------------------------------

/**
 * Fetch Bank_balance from the FSEconomy API and cache it in options.
 *
 * Run automatically every 30 minutes via WP-Cron. Can be forced manually
 * from the admin page.
 *
 * @param bool $force Skip the overlap lock when true.
 * @return bool True on success, false on failure (see fse_balance_last_error).
 */
function fse_balance_fetch_data($force = false) {
    // Prevent overlapping runs (cron + manual refresh).
    if (get_transient(FSE_BALANCE_LOCK) && !$force) {
        return false;
    }
    set_transient(FSE_BALANCE_LOCK, 1, 60);

    $api_url = get_option('fse_balance_api_url', '');

    // No URL configured yet — nothing to fetch.
    if (empty($api_url)) {
        update_option('fse_balance_last_error', 'No API URL configured.');
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    // Reject non-FSEconomy URLs.
    if (!fse_balance_is_allowed_url($api_url)) {
        update_option('fse_balance_last_error', 'URL not allowed. Only fseconomy.net is permitted.');
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    // Remote request with safe defaults.
    $response = wp_remote_get($api_url, [
        'timeout'            => 15,
        'redirection'        => 2,
        'reject_unsafe_urls' => true,
        'headers'            => [
            'Accept' => 'application/xml, text/xml',
        ],
    ]);

    if (is_wp_error($response)) {
        update_option('fse_balance_last_error', 'HTTP error: ' . $response->get_error_message());
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    if ((int) wp_remote_retrieve_response_code($response) !== 200) {
        update_option('fse_balance_last_error', 'HTTP ' . (int) wp_remote_retrieve_response_code($response));
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    $body = wp_remote_retrieve_body($response);

    if (empty($body) || strlen($body) > FSE_BALANCE_MAX_BODY_SIZE) {
        update_option('fse_balance_last_error', 'Empty or oversized response.');
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    // Parse XML without network access (XXE protection).
    $prev = libxml_use_internal_errors(true);
    $xml  = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$xml) {
        update_option('fse_balance_last_error', 'Invalid XML.');
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    if (!isset($xml->Statistic->Bank_balance)) {
        update_option('fse_balance_last_error', 'Bank_balance not found in XML.');
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    $bank = (string) $xml->Statistic->Bank_balance;

    if ($bank === '' || !is_numeric($bank)) {
        update_option('fse_balance_last_error', 'Non-numeric Bank_balance.');
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    // Cache the fresh values.
    update_option('fse_balance_value', $bank);
    update_option('fse_balance_last_update', current_time('mysql'));
    delete_option('fse_balance_last_error');

    delete_transient(FSE_BALANCE_LOCK);
    return true;
}

// ----------------------------------------------------------------------------
// 5. Shortcode [fse_balance]
// ----------------------------------------------------------------------------

/**
 * Render the cached balance as formatted currency.
 *
 * @return string e.g. "$12,345.67"
 */
function fse_balance_render_shortcode() {
    $value = get_option('fse_balance_value', 0);
    $value = number_format((float) $value, 2, '.', ',');

    return '$' . esc_html($value);
}

// ----------------------------------------------------------------------------
// 6. Admin settings page
// ----------------------------------------------------------------------------

/**
 * Register the admin menu entry.
 */
function fse_balance_register_menu() {
    add_menu_page(
        'FSE Balance',
        'FSE Balance',
        'manage_options',
        'fse-balance',
        'fse_balance_settings_page'
    );
}

/**
 * Render the settings page: API URL form, manual refresh, status.
 */
function fse_balance_settings_page() {
    // Capability check.
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.'));
    }

    // Handle URL save (CSRF-protected).
    if (isset($_POST['fse_api_url'])) {
        check_admin_referer('fse_balance_save_url');

        $new_url = esc_url_raw(wp_unslash($_POST['fse_api_url']));

        if (!empty($new_url) && !fse_balance_is_allowed_url($new_url)) {
            echo '<div class="error"><p>URL not allowed. Only fseconomy.net is permitted (http/https, ports 80/443).</p></div>';
        } else {
            update_option('fse_balance_api_url', $new_url);
            echo '<div class="updated"><p>URL saved.</p></div>';
        }
    }

    // Handle manual refresh (CSRF-protected).
    if (isset($_POST['fse_force_update'])) {
        check_admin_referer('fse_balance_force_update');

        $ok = fse_balance_fetch_data(true);
        if ($ok) {
            echo '<div class="updated"><p>Balance refreshed successfully.</p></div>';
        } else {
            $err = get_option('fse_balance_last_error', 'Unknown error.');
            echo '<div class="error"><p>Refresh failed: ' . esc_html($err) . '</p></div>';
        }
    }

    // Load current state for display.
    $api_url     = get_option('fse_balance_api_url', '');
    $value       = get_option('fse_balance_value', '0');
    $last_update = get_option('fse_balance_last_update', 'Never');
    $last_error  = get_option('fse_balance_last_error', '');

    $next     = wp_next_scheduled(FSE_BALANCE_CRON_HOOK);
    $next_run = $next ? date_i18n('Y-m-d H:i:s', $next) : 'Not scheduled';

    ?>
    <div class="wrap">
        <h1>FSE Balance</h1>
        <p>Displays any FSEconomy account or group bank balance with the <code>[fse_balance]</code> shortcode. It fetches <code>Bank_balance</code> from the FSEconomy API automatically every 30 minutes and serves the cached value to visitors.</p>
        <p class="description">
            Version <strong><?php echo esc_html(FSE_BALANCE_VERSION); ?></strong>
            &nbsp;|&nbsp; By <strong><a href="https://github.com/leuros88" target="_blank" rel="noopener">Leuros88</a></strong>
            &nbsp;|&nbsp; <a href="https://github.com/leuros88/Balance-Plugin-Wodpress-for-FSEconomy" target="_blank" rel="noopener">GitHub repository</a>
        </p>

        <form method="post">
            <?php wp_nonce_field('fse_balance_save_url'); ?>
            <h2>Settings</h2>

            <label for="fse_api_url"><strong>API URL:</strong></label><br>
            <input type="url" id="fse_api_url" name="fse_api_url" value="<?php echo esc_attr($api_url); ?>" style="width: 100%; max-width: 600px;" placeholder="https://server.fseconomy.net/...">
            <p class="description">Paste your FSEconomy API URL here, including your API key.</p>
            <br>

            <button class="button button-primary">Save URL</button>
        </form>

        <hr>

        <h2>Balance refresh</h2>
        <p>You can force a balance refresh at any time.</p>
        <form method="post">
            <?php wp_nonce_field('fse_balance_force_update'); ?>
            <button name="fse_force_update" value="1" class="button button-secondary">Force balance refresh now</button>
        </form>

        <h2>Status</h2>

        <p><strong>Last saved value:</strong> <?php echo esc_html('$' . number_format((float) $value, 2, '.', ',')); ?></p>
        <p><strong>Last update:</strong> <?php echo esc_html($last_update); ?></p>
        <p><strong>Next scheduled run (every 30 min):</strong> <?php echo esc_html($next_run); ?></p>
        <?php if (!empty($last_error)) : ?>
            <p><strong>Last error:</strong> <?php echo esc_html($last_error); ?></p>
        <?php endif; ?>
        <p><em>Note: WP-Cron runs on site visits. On low-traffic sites, set up a real system cron calling wp-cron.php every 30 min.</em></p>

        <h2>Plugin updates</h2>
        <p>Installed version: <strong><?php echo esc_html(FSE_BALANCE_VERSION); ?></strong></p>
        <?php $check_url = wp_nonce_url(admin_url('admin.php?page=fse-balance&fse_balance_check_update=1'), 'fse_balance_check_update'); ?>
        <p><a class="button button-secondary" href="<?php echo esc_url($check_url); ?>">Check for updates now</a></p>
        <p><em>Updates are published as Releases on GitHub. You can enable automatic updates in <a href="<?php echo esc_url(admin_url('plugins.php')); ?>">Plugins</a> with “Enable auto-updates”.</em></p>

        <hr>
        <p class="description">FSE Balance v<?php echo esc_html(FSE_BALANCE_VERSION); ?> by Leuros88 — <a href="https://github.com/leuros88/Balance-Plugin-Wodpress-for-FSEconomy" target="_blank" rel="noopener">GitHub repository</a></p>
    </div>
    <?php
}

// ----------------------------------------------------------------------------
// 7. Auto-update from GitHub Releases
// ----------------------------------------------------------------------------

/**
 * Get the latest GitHub release (cached 12h).
 *
 * Expects tags like "1.3" or "v1.3". The zip attached to the release is
 * preferred as download package; falls back to zipball_url.
 *
 * @return array|false Release data (version, package, url, notes, published_at).
 */
function fse_balance_get_github_release() {
    $cached = get_site_transient(FSE_BALANCE_GITHUB_CACHE_KEY);
    if (is_array($cached) && isset($cached['version'])) {
        return $cached;
    }

    $args = [
        'timeout' => 10,
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'WordPress/FSE-Balance',
        ],
    ];

    // Optional token for private repos / higher rate limits.
    // Define FSE_BALANCE_GITHUB_TOKEN in wp-config.php or use the filter.
    $token = defined('FSE_BALANCE_GITHUB_TOKEN') ? FSE_BALANCE_GITHUB_TOKEN : '';
    $token = apply_filters('fse_balance_github_token', $token);
    if (!empty($token)) {
        $args['headers']['Authorization'] = 'Bearer ' . $token;
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/' . FSE_BALANCE_GITHUB_REPO . '/releases/latest',
        $args
    );

    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['tag_name'])) {
        return false;
    }

    $version = ltrim(trim($data['tag_name']), 'vV');
    if ($version === '') {
        return false;
    }

    // Prefer an attached .zip asset, otherwise use the auto-generated zipball.
    $package = isset($data['zipball_url']) ? $data['zipball_url'] : '';
    if (!empty($data['assets']) && is_array($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            if (!empty($asset['browser_download_url']) && substr($asset['browser_download_url'], -4) === '.zip') {
                $package = $asset['browser_download_url'];
                break;
            }
        }
    }

    if (empty($package)) {
        return false;
    }

    $release = [
        'version'      => $version,
        'package'      => $package,
        'url'          => isset($data['html_url']) ? $data['html_url'] : ('https://github.com/' . FSE_BALANCE_GITHUB_REPO),
        'notes'        => isset($data['body']) ? $data['body'] : '',
        'published_at' => isset($data['published_at']) ? $data['published_at'] : '',
    ];

    set_site_transient(FSE_BALANCE_GITHUB_CACHE_KEY, $release, 12 * HOUR_IN_SECONDS);

    return $release;
}

/**
 * Inject update info into the "Dashboard > Updates" / Plugins screen.
 *
 * @param object $transient Update_plugins transient.
 * @return object
 */
function fse_balance_check_github_update($transient) {
    if (!is_object($transient) || empty($transient->checked)) {
        return $transient;
    }

    $basename = plugin_basename(__FILE__);
    if (!isset($transient->checked[$basename])) {
        return $transient;
    }

    $release = fse_balance_get_github_release();
    if (!$release || version_compare($release['version'], $transient->checked[$basename], '<=')) {
        return $transient;
    }

    $slug = dirname($basename);

    $transient->response[$basename] = (object) [
        'slug'        => $slug,
        'plugin'      => $basename,
        'new_version' => $release['version'],
        'url'         => $release['url'],
        'package'     => $release['package'],
        'tested'      => get_bloginfo('version'),
    ];

    return $transient;
}

/**
 * Provide the "View details" popup for the plugin.
 *
 * @param mixed  $result Existing result.
 * @param string $action Action name.
 * @param object $args   Query args (expects ->slug).
 * @return mixed
 */
function fse_balance_github_plugin_info($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug)) {
        return $result;
    }

    $basename = plugin_basename(__FILE__);
    if ($args->slug !== dirname($basename)) {
        return $result;
    }

    $release = fse_balance_get_github_release();
    if (!$release) {
        return $result;
    }

    return (object) [
        'name'          => 'FSE Balance',
        'slug'          => dirname($basename),
        'version'       => $release['version'],
        'author'        => '<a href="https://github.com/leuros88">Leuros88</a>',
        'homepage'      => $release['url'],
        'requires'      => '6.0',
        'tested'        => get_bloginfo('version'),
        'requires_php'  => '7.4',
        'download_link' => $release['package'],
        'sections'      => [
            'description' => 'Displays the FSEconomy bank balance using the [fse_balance] shortcode.',
            'changelog'   => !empty($release['notes']) ? nl2br(esc_html($release['notes'])) : 'See releases on GitHub.',
        ],
    ];
}

/**
 * Fix the extracted folder name after a GitHub update.
 *
 * GitHub zipballs extract to "{repo}-{tag}" instead of our plugin slug,
 * which would duplicate the plugin. Rename it back.
 *
 * @param mixed $response   Installation response.
 * @param array $hook_extra Extra args (contains plugin file when updating).
 * @param array $result     Result (contains destination path).
 * @return mixed
 */
function fse_balance_fix_github_folder($response, $hook_extra, $result) {
    if (!isset($hook_extra['plugin'])) {
        return $response;
    }

    if ($hook_extra['plugin'] !== plugin_basename(__FILE__)) {
        return $response;
    }

    global $wp_filesystem;
    if (!$wp_filesystem) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }

    $proper_dir = WP_PLUGIN_DIR . '/' . dirname(plugin_basename(__FILE__));
    if ($result['destination'] !== $proper_dir && $wp_filesystem->is_dir($result['destination'])) {
        // Remove any stale copy, then move into place.
        $wp_filesystem->delete($proper_dir, true);
        $wp_filesystem->move($result['destination'], $proper_dir);
        $result['destination'] = $proper_dir;
    }

    // Force WP to re-check updates right after install.
    delete_site_transient(FSE_BALANCE_GITHUB_CACHE_KEY);
    delete_site_transient('update_plugins');

    return $response;
}

/**
 * Manual "check for updates" handler on our settings page.
 * Clears the cache and forces WP to refresh plugin update data.
 */
function fse_balance_maybe_force_update_check() {
    if (!is_admin() || !current_user_can('update_plugins')) {
        return;
    }

    if (!isset($_GET['fse_balance_check_update'], $_GET['_wpnonce'])) {
        return;
    }

    if (!wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'fse_balance_check_update')) {
        return;
    }

    delete_site_transient(FSE_BALANCE_GITHUB_CACHE_KEY);
    delete_site_transient('update_plugins');
    wp_update_plugins();

    add_action('admin_notices', function () {
        echo '<div class="notice notice-success is-dismissible"><p>FSE Balance update check completed. See <a href="' . esc_url(admin_url('plugins.php')) . '">Plugins</a>.</p></div>';
    });
}

// ----------------------------------------------------------------------------
// 8. Hook registration (bootstrap)
// ----------------------------------------------------------------------------

add_filter('cron_schedules', 'fse_balance_add_cron_interval');

register_activation_hook(__FILE__, 'fse_balance_schedule_event');
register_deactivation_hook(__FILE__, 'fse_balance_on_deactivation');
register_uninstall_hook(__FILE__, 'fse_balance_uninstall');

// Self-healing: re-schedule if WP-Cron lost the event (WP-Cron needs visits).
add_action('init', 'fse_balance_schedule_event');

// Periodic API check, every 30 minutes.
add_action(FSE_BALANCE_CRON_HOOK, 'fse_balance_fetch_data');

// Public shortcode.
add_shortcode('fse_balance', 'fse_balance_render_shortcode');

// Admin UI.
add_action('admin_menu', 'fse_balance_register_menu');

// Self-hosted updates from GitHub Releases.
add_filter('pre_set_site_transient_update_plugins', 'fse_balance_check_github_update');
add_filter('plugins_api', 'fse_balance_github_plugin_info', 10, 3);
add_filter('upgrader_post_install', 'fse_balance_fix_github_folder', 10, 3);
add_action('admin_init', 'fse_balance_maybe_force_update_check');
