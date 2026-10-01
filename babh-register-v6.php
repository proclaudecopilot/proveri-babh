<?php
/**
 * Plugin Name: Регистър на добавките — версия 6
 * Description: Търсене в данни от регистъра на хранителните добавки на БАБХ. Качване на Excel файловете на регистъра или автоматично изтегляне от портала на БАБХ по график, преглед на продукти, профили на производители и търговци, класация и изпреварвания (Pro), изтегляне на резултатите в CSV. Работи като инсталируемо приложение (PWA). За вграждане в страница: [babh_register].
 * Version: 6.8.0
 * GitHub Plugin URI: proclaudecopilot/proveri-babh
 * Author: BABH Register
 * Requires PHP: 7.4
 * Text Domain: babh-register-v6
 */

if (!defined('ABSPATH')) exit;

define('BABH6_VERSION', '6.8.0');
/* Версия на правилата за производни данни (категории, автоматични бележки, ключове на фирмите).
   При промяна всички записи се преизчисляват на порции (AD-01). */
define('BABH6_RULES_VERSION', '2026-09-30.1');
define('BABH6_PATH', plugin_dir_path(__FILE__));
define('BABH6_URL', plugin_dir_url(__FILE__));

require_once BABH6_PATH . 'includes/schema.php';
require_once BABH6_PATH . 'includes/detect.php';
require_once BABH6_PATH . 'includes/xlsx-reader.php';
require_once BABH6_PATH . 'includes/etl.php';
require_once BABH6_PATH . 'includes/sync.php';
require_once BABH6_PATH . 'includes/ai.php';
require_once BABH6_PATH . 'includes/rest.php';
require_once BABH6_PATH . 'includes/parties.php';
require_once BABH6_PATH . 'includes/rank.php';
require_once BABH6_PATH . 'includes/infer.php';
require_once BABH6_PATH . 'includes/frontend.php';
require_once BABH6_PATH . 'includes/pwa.php';
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

/* Upgrade-safe: пресъздава таблиците при промяна на версията (на init — важи и за WP-Cron, не само за админа).
   Версията се записва чак след като миграционните стъпки са изпълнени (AD-06); при прекъсване се повтарят. */
add_action('init', function () {
    if (get_option('babh6_version') !== BABH6_VERSION) {
        if (get_transient('babh6_migrating')) return;
        set_transient('babh6_migrating', 1, 5 * MINUTE_IN_SECONDS);
        babh6_create_tables();
        /* Години под 2000 или в бъдещето са от грешни рег. номера → без година (v6.5) */
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE " . babh6_table('products') . " SET ryear = NULL WHERE ryear IS NOT NULL AND (ryear < 2000 OR ryear > %d)", (int)gmdate('Y') + 1));
        delete_transient('babh6_stats');
        delete_transient('babh6_health');
        update_option('babh6_version', BABH6_VERSION);
        delete_transient('babh6_migrating');
    }
    /* Производните данни се преизчисляват на порции при нова версия на правилата (AD-01) */
    if (get_option('babh6_rules_version') !== BABH6_RULES_VERSION && !get_option('babh6_renorm')) {
        babh6_renorm_start();
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
			echo '<div class="notice notice-warning"><p><b>Регистър на добавките:</b> автоматичното обновяване не е настроено: файлът <code>logador-github-updater.php</code> не може да бъде записан в <code>' . esc_html( $dir ) . '</code>. Провери правата за запис или копирай файла ръчно от <code>' . esc_html( $src ) . '</code>.</p></div>';
		} );
		return;
	}
	add_action( 'admin_notices', function () use ( $want ) {
		echo '<div class="notice notice-success is-dismissible"><p><b>LOGADOR GitHub Updater ' . esc_html( $want ) . '</b> е инсталиран. При нужда от достъп до частно хранилище ключът за достъп до GitHub се задава в <a href="' . esc_url( admin_url( 'options-general.php?page=logador-github' ) ) . '">Настройки → Обновявания от GitHub</a>.</p></div>';
	} );
} );
