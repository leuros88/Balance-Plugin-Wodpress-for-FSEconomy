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
 * Description: Displays the FSEconomy bank balance using the [fse_balance] shortcode. Fetches Bank_balance from the FSEconomy API every 30 minutes and caches it.
 * Version: 1.2
 * Author: Leuros88
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: fse-balance
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
// 7. Hook registration (bootstrap)
// ============================================================================

// ----------------------------------------------------------------------------
// 0. Security guard & constants
// ----------------------------------------------------------------------------

defined('ABSPATH') || exit;

define('FSE_BALANCE_CRON_HOOK', 'fse_balance_cron_event');
define('FSE_BALANCE_INTERVAL', 'every_thirty_minutes');
define('FSE_BALANCE_LOCK', 'fse_balance_fetch_lock');
define('FSE_BALANCE_MAX_BODY_SIZE', 500000); // 500 KB max XML response

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
        update_option('fse_balance_last_error', 'Sin URL configurada.');
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    // Reject non-FSEconomy URLs.
    if (!fse_balance_is_allowed_url($api_url)) {
        update_option('fse_balance_last_error', 'URL no permitida. Solo se permite fseconomy.net.');
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
        update_option('fse_balance_last_error', 'Error HTTP: ' . $response->get_error_message());
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
        update_option('fse_balance_last_error', 'Respuesta vacía o demasiado grande.');
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    // Parse XML without network access (XXE protection).
    $prev = libxml_use_internal_errors(true);
    $xml  = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$xml) {
        update_option('fse_balance_last_error', 'XML inválido.');
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    if (!isset($xml->Statistic->Bank_balance)) {
        update_option('fse_balance_last_error', 'Bank_balance no encontrado en XML.');
        delete_transient(FSE_BALANCE_LOCK);
        return false;
    }

    $bank = (string) $xml->Statistic->Bank_balance;

    if ($bank === '' || !is_numeric($bank)) {
        update_option('fse_balance_last_error', 'Bank_balance no numérico.');
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
        wp_die(esc_html__('No tienes permiso para acceder a esta página.'));
    }

    // Handle URL save (CSRF-protected).
    if (isset($_POST['fse_api_url'])) {
        check_admin_referer('fse_balance_save_url');

        $new_url = esc_url_raw(wp_unslash($_POST['fse_api_url']));

        if (!empty($new_url) && !fse_balance_is_allowed_url($new_url)) {
            echo '<div class="error"><p>URL no permitida. Solo se permite fseconomy.net (http/https, puertos 80/443).</p></div>';
        } else {
            update_option('fse_balance_api_url', $new_url);
            echo '<div class="updated"><p>URL actualizada.</p></div>';
        }
    }

    // Handle manual refresh (CSRF-protected).
    if (isset($_POST['fse_force_update'])) {
        check_admin_referer('fse_balance_force_update');

        $ok = fse_balance_fetch_data(true);
        if ($ok) {
            echo '<div class="updated"><p>Actualización forzada realizada.</p></div>';
        } else {
            $err = get_option('fse_balance_last_error', 'Error desconocido.');
            echo '<div class="error"><p>Falló la actualización: ' . esc_html($err) . '</p></div>';
        }
    }

    // Load current state for display.
    $api_url     = get_option('fse_balance_api_url', '');
    $value       = get_option('fse_balance_value', '0');
    $last_update = get_option('fse_balance_last_update', 'Nunca');
    $last_error  = get_option('fse_balance_last_error', '');

    $next     = wp_next_scheduled(FSE_BALANCE_CRON_HOOK);
    $next_run = $next ? date_i18n('Y-m-d H:i:s', $next) : 'No programada';

    ?>
    <div class="wrap">
        <h1>FSE Balance</h1>

        <form method="post">
            <?php wp_nonce_field('fse_balance_save_url'); ?>
            <h2>Configuración</h2>

            <label for="fse_api_url"><strong>URL de la API:</strong></label><br>
            <input type="url" id="fse_api_url" name="fse_api_url" value="<?php echo esc_attr($api_url); ?>" style="width: 100%; max-width: 600px;" placeholder="https://server.fseconomy.net/...">
            <br><br>

            <button class="button button-primary">Guardar URL</button>
        </form>

        <hr>

        <form method="post">
            <?php wp_nonce_field('fse_balance_force_update'); ?>
            <button name="fse_force_update" value="1" class="button button-secondary">Forzar actualización ahora</button>
        </form>

        <h2>Estado</h2>

        <p><strong>Último valor guardado:</strong> <?php echo esc_html('$' . number_format((float) $value, 2, '.', ',')); ?></p>
        <p><strong>Última actualización:</strong> <?php echo esc_html($last_update); ?></p>
        <p><strong>Próxima ejecución programada (cada 30 min):</strong> <?php echo esc_html($next_run); ?></p>
        <?php if (!empty($last_error)) : ?>
            <p><strong>Último error:</strong> <?php echo esc_html($last_error); ?></p>
        <?php endif; ?>
        <p><em>Nota: WP-Cron se ejecuta con las visitas a la web. Si tienes poco tráfico, usa un cron real del sistema llamando a wp-cron.php cada 30 min.</em></p>
    </div>
    <?php
}

// ----------------------------------------------------------------------------
// 7. Hook registration (bootstrap)
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
