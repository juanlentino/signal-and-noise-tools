<?php
/**
 * Signal & Noise Tools: file integrity's live sources, watch and schedule
 * (2026-10-18). The scan is in inc/file-integrity.php.
 *
 * The file_integrity watch is ripe on any modified core or checked plugin
 * file, an unexpected PHP file in wp-admin/ or wp-includes/, core checksums
 * that could not be fetched, a core pass the budget cut short, or a check that
 * has not run for two days. A blind or silent run is never a clean one. A
 * missing core file alone is reported, not ripe: hosts delete readme.html.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One plugin's checksums from wordpress.org: relative path => {md5, sha256};
 * null when wordpress.org has none (404), false on any other failure.
 *
 * @param string $slug    Plugin slug.
 * @param string $version Installed version.
 * @return array|null|false
 */
function snt_fi_fetch_plugin_checksums( $slug, $version ) {
	if ( '' === $version ) {
		return null;
	}
	$res = wp_remote_get( 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json', array( 'timeout' => 10 ) );
	if ( is_wp_error( $res ) ) {
		return false;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 404 === $code ) {
		return null;
	}
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return 200 === $code && is_array( $body['files'] ?? null ) ? $body['files'] : false;
}

/** The live sources: core's own functions and the site's install. */
function snt_fi_live_deps() {
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	global $wp_version, $wp_local_package;
	return array(
		'root'             => ABSPATH,
		'plugin_dir'       => WP_PLUGIN_DIR,
		'version'          => (string) $wp_version,
		'locale'           => empty( $wp_local_package ) ? 'en_US' : (string) $wp_local_package, // As core's updater does.
		'core_checksums'   => 'get_core_checksums',
		'plugins'          => 'get_plugins',
		'updates'          => static fn() => get_site_transient( 'update_plugins' ),
		'plugin_checksums' => 'snt_fi_fetch_plugin_checksums',
		'now'              => time(),
		'max_files'        => 20000,
		'seconds'          => 20,
	);
}

/** Daily: run the check against the live install. */
function snt_fi_cron() {
	snt_fi_run( snt_fi_live_deps() );
}

/** Watch: file integrity. */
function snt_watch_ripe_file_integrity( $watch, $now ) {
	unset( $watch );
	$last = get_option( SN_FI_OPT, array() );
	if ( ! is_array( $last ) || empty( $last['at'] ) ) {
		return array( 'ripe' => false, 'note' => 'not run yet' );
	}
	$c     = $last['core'];
	$p     = $last['plugins'];
	$paths = implode( ', ', (array) $last['paths'] );
	if ( ! empty( $c['error'] ) ) {
		return array( 'ripe' => true, 'note' => 'core checksums could not be fetched from wordpress.org, so core went unchecked' );
	}
	if ( (int) $c['modified'] + (int) $c['added'] + (int) $p['modified'] > 0 ) {
		return array( 'ripe' => true, 'note' => sprintf( '%d core files modified, %d unexpected PHP files in wp-admin/ or wp-includes/, %d plugin files modified: %s. Compare each with a fresh copy before trusting the site.', (int) $c['modified'], (int) $c['added'], (int) $p['modified'], $paths ) );
	}
	if ( empty( $c['complete'] ) ) {
		return array( 'ripe' => true, 'note' => sprintf( 'the run ran out of budget after %d core files, too few to call core clean', (int) $c['checked'] ) );
	}
	if ( (int) $now - (int) $last['at'] > 2 * DAY_IN_SECONDS ) {
		return array( 'ripe' => true, 'note' => 'the daily check has not run since ' . gmdate( 'Y-m-d H:i', (int) $last['at'] ) . ' UTC' );
	}
	$extra = array();
	foreach ( array( 'missing' => array( $c, 'core files missing' ), 'unfinished' => array( $p, 'plugins left for lack of budget' ), 'errors' => array( $p, 'plugin checksum lists unreadable' ) ) as $k => $pair ) {
		if ( (int) ( $pair[0][ $k ] ?? 0 ) > 0 ) {
			$extra[] = (int) $pair[0][ $k ] . ' ' . $pair[1];
		}
	}
	return array( 'ripe' => false, 'note' => sprintf( 'clean: %d core files, %d plugin files; %d plugins not checkable%s', (int) $c['checked'], (int) $p['checked'], (int) $p['not_checkable'], $extra ? '; ' . implode( ', ', $extra ) : '' ) );
}

if ( ! defined( 'SN_FILE_INTEGRITY_TEST' ) || ! SN_FILE_INTEGRITY_TEST ) {
	add_action( SN_FI_HOOK, 'snt_fi_cron', 10, 0 );
	add_action(
		'init',
		static function () {
			if ( ! wp_next_scheduled( SN_FI_HOOK ) ) {
				wp_schedule_event( time() + 25 * MINUTE_IN_SECONDS, 'daily', SN_FI_HOOK );
			}
		}
	);
}
