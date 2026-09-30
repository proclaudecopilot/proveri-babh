<?php
/**
 * Plugin Name: LOGADOR GitHub Updater
 * Description: Обновяване на съвместимите плъгини от GitHub през стандартния механизъм на WordPress. При нужда от частен достъп се използва общ ключ за сайта. Компонентът се инсталира автоматично от плъгините на LOGADOR.
 * Version: 1.0.3
 * Author: LOGADOR
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( defined( 'LOGADOR_GH_UPDATER_VERSION' ) ) return; // вече е зареден (от друго копие)
define( 'LOGADOR_GH_UPDATER_VERSION', '1.0.3' );

final class LOGADOR_GitHub_Updater {
	const OPT_TOKEN = 'logador_github_token';
	const CACHE     = 'logador_gh_releases';
	const TTL       = 6 * HOUR_IN_SECONDS;
	const TTL_ERR   = 30 * MINUTE_IN_SECONDS; // временен отказ се повтаря по-скоро (GH-03)

	private static $inst;
	public static function instance() { return self::$inst ?: ( self::$inst = new self() ); }

	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'auth' ), 10, 2 );
		add_filter( 'extra_plugin_headers', function ( $h ) { $h[] = 'GitHub Plugin URI'; return $h; } );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_logador_gh_save', array( $this, 'save' ) );
		add_action( 'admin_post_logador_gh_check', array( $this, 'check' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/* ---------- кои плъгини следим ---------- */
	public static function tracked() {
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$out = array();
		foreach ( get_plugins() as $file => $h ) {
			$repo = trim( (string) ( $h['GitHub Plugin URI'] ?? '' ) );
			$repo = preg_replace( '#^https?://github\.com/#', '', $repo ); $repo = rtrim( $repo, '/' ); $repo = preg_replace( '/\.git$/', '', $repo );
			if ( ! preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ) continue;
			$out[ $file ] = array( 'file' => $file, 'slug' => dirname( $file ), 'name' => $h['Name'], 'version' => $h['Version'], 'repo' => $repo );
		}
		return $out;
	}
	public static function token() { return trim( (string) get_option( self::OPT_TOKEN, '' ) ); }

	/* ---------- GitHub ---------- */
	public static function release( $repo, $force = false ) {
		$all = get_site_transient( self::CACHE ); if ( ! is_array( $all ) ) $all = array();
		if ( ! $force && isset( $all[ $repo ] ) && ( $all[ $repo ]['at'] ?? 0 ) > time() - ( empty( $all[ $repo ]['data'] ) ? self::TTL_ERR : self::TTL ) ) return $all[ $repo ];
		$args = array( 'timeout' => 12, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'logador-gh-updater/' . LOGADOR_GH_UPDATER_VERSION ) );
		if ( self::token() ) $args['headers']['Authorization'] = 'Bearer ' . self::token();
		$res  = wp_remote_get( 'https://api.github.com/repos/' . $repo . '/releases/latest', $args );
		$row  = array( 'at' => time(), 'data' => null, 'error' => '' );
		if ( is_wp_error( $res ) ) { $row['error'] = 'Неуспешна връзка с GitHub. ' . $res->get_error_message(); }
		else {
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( 200 === $code && is_array( $body ) && ! empty( $body['tag_name'] ) ) {
				$pkg = ''; $api = ''; $slug = basename( $repo );
				$zips = array();
				foreach ( (array) ( $body['assets'] ?? array() ) as $a ) { if ( preg_match( '/\.zip$/i', (string) ( $a['name'] ?? '' ) ) ) $zips[] = $a; }
				// Пакетът на плъгина е <slug>.zip (или съдържа името на плъгина); друг ZIP в release-а не се приема за пакет (GH-02)
				foreach ( $zips as $a ) { if ( strtolower( (string) $a['name'] ) === strtolower( $slug . '.zip' ) ) { $pkg = (string) $a['browser_download_url']; $api = (string) $a['url']; break; } }
				if ( $pkg === '' ) foreach ( $zips as $a ) { if ( false !== stripos( (string) $a['name'], $slug ) ) { $pkg = (string) $a['browser_download_url']; $api = (string) $a['url']; break; } }
				if ( $pkg === '' && count( $zips ) === 1 ) { $pkg = (string) $zips[0]['browser_download_url']; $api = (string) $zips[0]['url']; }
				$row['data'] = array( 'version' => ltrim( (string) $body['tag_name'], 'vV' ), 'tag' => (string) $body['tag_name'], 'package' => $pkg ?: (string) ( $body['zipball_url'] ?? '' ), 'asset_api' => $api,
					'notes' => (string) ( $body['body'] ?? '' ), 'published' => (string) ( $body['published_at'] ?? '' ), 'html_url' => (string) ( $body['html_url'] ?? '' ) );
			} elseif ( 404 === $code ) {
				$row['error'] = self::token() ? 'GitHub не върна достъпна публикувана версия за ' . $repo . ' (HTTP 404). Провери адреса на хранилището, наличието на версия и достъпа на ключа.' : 'GitHub не върна достъпна публикувана версия (HTTP 404). Провери адреса на хранилището и дали има публикувана версия. За частно хранилище е необходим достъп.';
			} elseif ( 401 === $code ) { $row['error'] = 'GitHub отказа удостоверяването (HTTP 401). Провери ключа за достъп.'; }
			else { $row['error'] = 'GitHub върна грешка HTTP ' . $code . '.' . ( isset( $body['message'] ) ? ' ' . $body['message'] : '' ); }
		}
		$all[ $repo ] = $row; set_site_transient( self::CACHE, $all, self::TTL );
		return $row;
	}

	/* ---------- WP ъпдейт механизъм ---------- */
	public function inject( $t ) {
		if ( ! is_object( $t ) ) $t = new stdClass();
		foreach ( self::tracked() as $file => $p ) {
			$r = self::release( $p['repo'] ); $d = $r['data'] ?? null;
			$item = array( 'id' => 'github.com/' . $p['repo'], 'slug' => $p['slug'], 'plugin' => $file, 'new_version' => $p['version'], 'url' => 'https://github.com/' . $p['repo'], 'package' => '', 'icons' => array(), 'banners' => array(), 'banners_rtl' => array(), 'tested' => '', 'requires_php' => '7.4', 'compatibility' => new stdClass() );
			if ( ! $d ) {
				// Неуспешна проверка ≠ актуална версия (GH-03): не влиза в no_update; състоянието се вижда в реда на плъгина
				unset( $t->response[ $file ] );
				if ( isset( $t->no_update[ $file ] ) ) unset( $t->no_update[ $file ] );
				continue;
			}
			if ( version_compare( $d['version'], $p['version'], '<=' ) ) {
				// Актуален: казваме го на WP през no_update — само така показва „Enable auto-updates“ и „Виж детайли“.
				unset( $t->response[ $file ] );
				if ( ! isset( $t->no_update ) || ! is_array( $t->no_update ) ) $t->no_update = array();
				$t->no_update[ $file ] = (object) $item;
				continue;
			}
			if ( isset( $t->no_update[ $file ] ) ) unset( $t->no_update[ $file ] );
			$item['new_version'] = $d['version']; $item['url'] = $d['html_url'];
			$item['package'] = ( self::token() && $d['asset_api'] ) ? $d['asset_api'] : $d['package'];
			$t->response[ $file ] = (object) $item;
		}
		return $t;
	}
	public function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) return $res;
		foreach ( self::tracked() as $p ) {
			if ( $p['slug'] !== $args->slug ) continue;
			$d = self::release( $p['repo'] )['data'] ?? null; if ( ! $d ) return $res;
			return (object) array( 'name' => $p['name'], 'slug' => $p['slug'], 'version' => $d['version'], 'author' => 'LOGADOR', 'homepage' => 'https://github.com/' . $p['repo'], 'download_link' => $d['package'], 'last_updated' => $d['published'],
				'sections' => array( 'changelog' => nl2br( esc_html( $d['notes'] ?: 'Виж описанието на версията в GitHub.' ) ) ) );
		}
		return $res;
	}
	/** zipball-ът се разархивира като owner-repo-<sha>/ — WP иска папка с името на плъгина. */
	public function fix_folder( $source, $remote, $upgrader, $extra ) {
		if ( empty( $extra['plugin'] ) ) return $source;
		$tr = self::tracked(); if ( ! isset( $tr[ $extra['plugin'] ] ) ) return $source;
		global $wp_filesystem;
		$want = trailingslashit( $remote ) . $tr[ $extra['plugin'] ]['slug'] . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) return $source;
		return ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) ? $want : $source;
	}
	/** Ключът се добавя само към заявки за точните хранилища на следените плъгини; чужд Authorization header не се презаписва (GH-01). */
	public static function url_is_ours( $url ) {
		foreach ( self::tracked() as $p ) {
			if ( 0 === strpos( $url, 'https://api.github.com/repos/' . $p['repo'] . '/' ) ) return true;
			if ( 0 === strpos( $url, 'https://github.com/' . $p['repo'] . '/' ) ) return true;
			if ( 0 === strpos( $url, 'https://api.github.com/repos/' . $p['repo'] ) && in_array( substr( $url, strlen( 'https://api.github.com/repos/' . $p['repo'] ) ), array( '', '?' ), true ) ) return true;
		}
		return false;
	}
	public function auth( $args, $url ) {
		if ( ! self::token() ) return $args;
		if ( ! self::url_is_ours( $url ) ) return $args;
		if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) $args['headers'] = array();
		foreach ( $args['headers'] as $hk => $hv ) { if ( strtolower( (string) $hk ) === 'authorization' && $hv !== '' ) return $args; }
		$args['headers']['Authorization'] = 'Bearer ' . self::token();
		if ( false !== strpos( $url, '/releases/assets/' ) ) $args['headers']['Accept'] = 'application/octet-stream';
		return $args;
	}
	public function row_meta( $links, $file ) {
		$tr = self::tracked();
		if ( isset( $tr[ $file ] ) ) {
			$links[] = '<a href="https://github.com/' . esc_attr( $tr[ $file ]['repo'] ) . '/releases" target="_blank" rel="noopener">Версии в GitHub</a>';
			$all = get_site_transient( self::CACHE ); $row = is_array( $all ) && isset( $all[ $tr[ $file ]['repo'] ] ) ? $all[ $tr[ $file ]['repo'] ] : null;
			if ( $row && empty( $row['data'] ) ) $links[] = '<span style="color:#d63638">Последната проверка за обновяване е неуспешна (' . esc_html( $row['error'] ?: 'няма данни' ) . ') — не е известно дали версията е актуална.</span>';
			elseif ( $row ) $links[] = '<span class="description">Проверено в GitHub: ' . esc_html( date_i18n( 'd.m.Y H:i', (int) $row['at'] ) ) . '</span>';
		}
		return $links;
	}

	/* ---------- Settings → GitHub ъпдейти ---------- */
	public function menu() { add_options_page( 'Обновявания от GitHub', 'Обновявания от GitHub', 'manage_options', 'logador-github', array( $this, 'page' ) ); }
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Нямаш права за това действие.' ); check_admin_referer( 'logador_gh_save' );
		$tok = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		if ( ! empty( $_POST['token_clear'] ) ) update_option( self::OPT_TOKEN, '', false );
		elseif ( $tok !== '' ) update_option( self::OPT_TOKEN, $tok, false ); // празно поле = запазеният ключ остава (AD-04)
		delete_site_transient( self::CACHE ); delete_site_transient( 'update_plugins' );
		wp_safe_redirect( admin_url( 'options-general.php?page=logador-github&saved=1' ) ); exit;
	}
	public function check() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Нямаш права за това действие.' ); check_admin_referer( 'logador_gh_check' );
		delete_site_transient( self::CACHE ); foreach ( self::tracked() as $p ) self::release( $p['repo'], true );
		delete_site_transient( 'update_plugins' ); wp_update_plugins();
		wp_safe_redirect( admin_url( 'options-general.php?page=logador-github&checked=1' ) ); exit;
	}
	public function page() {
		$tr = self::tracked();
		echo '<div class="wrap"><h1>Обновявания от GitHub (LOGADOR)</h1>';
		if ( ! empty( $_GET['saved'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Настройките са запазени.</p></div>';
		if ( ! empty( $_GET['checked'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Проверката приключи. Резултатът за всеки плъгин е в таблицата.</p></div>';
		echo '<p>Тук се проверяват плъгините, които имат зададено GitHub хранилище (ред <code>GitHub Plugin URI: owner/repo</code> в описанието на плъгина). За частен достъп добави ключа на сайта. Новите версии се инсталират от страницата <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Плъгини</a>.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'logador_gh_save' ); echo '<input type="hidden" name="action" value="logador_gh_save">';
		$tok = self::token();
		echo '<table class="form-table"><tr><th><label for="logador_gh_token">Ключ за достъп до GitHub (token)</label></th><td><input type="password" id="logador_gh_token" name="token" class="regular-text" value="" placeholder="' . ( $tok ? 'Запазен ключ: …' . esc_attr( substr( $tok, -4 ) ) . ' (въведи нов, за да го замениш)' : '' ) . '" autocomplete="new-password">' . ( $tok ? '<p><label><input type="checkbox" name="token_clear" value="1"> Премахни ключа</label></p>' : '' ) . '<p class="description">Използвай ключ за достъп до хранилищата на плъгините, които ще обновяваш (в GitHub: fine-grained token с право Contents: Read за съответните хранилища). Един ключ за целия сайт. Публично хранилище не изисква ключ.</p></td></tr></table>';
		submit_button( 'Запази настройките' ); echo '</form>';
		echo '<h2>Плъгини с обновяване от GitHub</h2><table class="widefat striped"><thead><tr><th>Плъгин</th><th>Хранилище</th><th>Инсталирана версия</th><th>Версия в GitHub</th><th>Състояние</th></tr></thead><tbody>';
		if ( ! $tr ) echo '<tr><td colspan="5">Няма плъгини със зададено GitHub хранилище.</td></tr>';
		foreach ( $tr as $p ) {
			$r = self::release( $p['repo'] ); $d = $r['data'] ?? null;
			$st = $d ? ( version_compare( $d['version'], $p['version'], '>' ) ? '<b style="color:#b8481c">Има нова версия. <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Отвори „Плъгини“</a>, за да я инсталираш.</b>' : '<span style="color:#1f7a4d">Не е намерена по-нова версия.</span>' ) : '<b style="color:#d64545">Непроверено: ' . esc_html( $r['error'] ?: 'Не е получена информация за версията.' ) . '</b>';
			$st .= ' <span class="description">(проверено ' . esc_html( date_i18n( 'd.m.Y H:i', (int) $r['at'] ) ) . ')</span>';
			echo '<tr><td><b>' . esc_html( $p['name'] ) . '</b></td><td><a href="https://github.com/' . esc_attr( $p['repo'] ) . '/releases" target="_blank">' . esc_html( $p['repo'] ) . '</a></td><td>' . esc_html( $p['version'] ) . '</td><td>' . esc_html( $d['version'] ?? 'Няма данни' ) . '</td><td>' . $st . '</td></tr>';
		}
		echo '</tbody></table><p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=logador_gh_check' ), 'logador_gh_check' ) ) . '">Провери за обновявания</a> &nbsp; <span class="description">Резултатите от GitHub се пазят до 6 часа. За нова проверка използвай бутона. Автоматичното обновяване се включва за всеки плъгин от страницата „Плъгини“.</span></p></div>';
	}
}
LOGADOR_GitHub_Updater::instance();
