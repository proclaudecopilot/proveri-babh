<?php
/**
 * Plugin Name: БАБХ Регистър v6
 * Description: Регистър на хранителните добавки — публично търсене (shortcode [babh_register]), REST API, ETL за Excel файлове от БАБХ, автоматично обновяване от портала на БАБХ по график, diff между качвания, регулаторни флагове. Работи паралелно с v5.6 (отделни таблици).
 * Version: 6.1.2
 * GitHub Plugin URI: proclaudecopilot/proveri-babh
 * Author: BABH Register
 * Requires PHP: 7.4
 * Text Domain: babh-register-v6
 */

if (!defined('ABSPATH')) exit;

define('BABH6_VERSION', '6.1.2');
define('BABH6_PATH', plugin_dir_path(__FILE__));
define('BABH6_URL', plugin_dir_url(__FILE__));

require_once BABH6_PATH . 'includes/schema.php';
require_once BABH6_PATH . 'includes/detect.php';
require_once BABH6_PATH . 'includes/xlsx-reader.php';
require_once BABH6_PATH . 'includes/etl.php';
require_once BABH6_PATH . 'includes/sync.php';
require_once BABH6_PATH . 'includes/ai.php';
require_once BABH6_PATH . 'includes/rest.php';
require_once BABH6_PATH . 'includes/frontend.php';
require_once BABH6_PATH . 'includes/site.php';
require_once BABH6_PATH . 'includes/admin.php';

register_activation_hook(__FILE__, 'babh6_activate');
function babh6_activate() {
    babh6_create_tables();
    update_option('babh6_version', BABH6_VERSION);
    babh6_sync_schedule_next();
}

register_deactivation_hook(__FILE__, 'babh6_deactivate');
function babh6_deactivate() {
    wp_clear_scheduled_hook('babh6_sync_check');
    wp_clear_scheduled_hook('babh6_sync_process');
}

/* Upgrade-safe: пресъздава таблиците при промяна на версията (на init — важи и за WP-Cron, не само за админа) */
add_action('init', function () {
    if (get_option('babh6_version') !== BABH6_VERSION) {
        babh6_create_tables();
        update_option('babh6_version', BABH6_VERSION);
    }
});

/* LOGADOR GitHub Updater — общ за всички плъгини на LOGADOR: копира се в wp-content/mu-plugins, ако липсва или е по-стар. */
add_action( 'admin_init', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) return;
	$src = BABH6_PATH . 'includes/mu/logador-github-updater.php';
	$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	$dst = $dir . '/logador-github-updater.php';
	if ( ! is_file( $src ) ) return;
	$want = preg_match( '/\*\s*Version:\s*([0-9.]+)/', (string) file_get_contents( $src ), $m ) ? $m[1] : '0';
	$have = is_file( $dst ) && preg_match( '/\*\s*Version:\s*([0-9.]+)/', (string) file_get_contents( $dst ), $m2 ) ? $m2[1] : '0';
	if ( is_file( $dst ) && version_compare( $have, $want, '>=' ) ) return;
	if ( ! is_dir( $dir ) ) @wp_mkdir_p( $dir );
	if ( ! is_dir( $dir ) || ! is_writable( $dir ) || ! @copy( $src, $dst ) ) {
		add_action( 'admin_notices', function () use ( $src, $dir ) {
			echo '<div class="notice notice-warning"><p><b>БАБХ Регистър v6:</b> не мога да запиша <code>logador-github-updater.php</code> в <code>' . esc_html( $dir ) . '</code> (права). Копирай го ръчно от <code>' . esc_html( $src ) . '</code>.</p></div>';
		} );
		return;
	}
	add_action( 'admin_notices', function () use ( $want ) {
		echo '<div class="notice notice-success is-dismissible"><p><b>LOGADOR GitHub Updater ' . esc_html( $want ) . '</b> е инсталиран. Token-ът се слага веднъж в <a href="' . esc_url( admin_url( 'options-general.php?page=logador-github' ) ) . '">Settings → GitHub ъпдейти</a>.</p></div>';
	} );
} );
