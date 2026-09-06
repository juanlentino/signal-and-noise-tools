<?php
/**
 * S&N Dashboard — Connections → Discography, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/music.php, `sn_admin_render_music_section()`)
 * paints, in the two-column shell: the intro, the sync status box (Synced /
 * Stale / Failed / Pending) and one form (`sn_action=music_save`: the masked,
 * constant-lockable Spotify credentials, the Muso.AI profile id, the featured
 * release) in the MAIN column; the Status facts and the "Sync now" form
 * (`sn_action=music_sync`) in the rail. Same readers, same forms, same fields,
 * same handlers; the kit's parts (connections-music-parts.php) instead of wp-admin's.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/connections-music-parts.php';

/**
 * The leaf's state, read the way the classic leaf reads it: the discography
 * store, the three wp-config locks, the two stored credentials, whether Spotify
 * resolves, the Muso profile id, and the owner's featured record.
 *
 * @return array<string,mixed>
 */
function music_state() {
	$store    = function_exists( 'sn_discography_get' ) ? sn_discography_get() : array();
	$store    = array_merge( array( 'last_synced' => 0, 'count' => 0, 'last_error' => '' ), is_array( $store ) ? $store : array() );
	$featured = function_exists( 'sn_music_featured_get' ) ? sn_music_featured_get() : array();
	$featured = is_array( $featured ) ? $featured : array();
	return array(
		'synced'        => (int) $store['last_synced'],
		'count'         => (int) $store['count'],
		'last_error'    => (string) $store['last_error'],
		'id_const'      => defined( 'SN_SPOTIFY_CLIENT_ID' ) && SN_SPOTIFY_CLIENT_ID,
		'secret_const'  => defined( 'SN_SPOTIFY_CLIENT_SECRET' ) && SN_SPOTIFY_CLIENT_SECRET,
		'profile_const' => defined( 'SN_MUSO_PROFILE_ID' ) && SN_MUSO_PROFILE_ID,
		'id_opt'        => defined( 'SN_SPOTIFY_ID_OPT' ) ? (string) get_option( SN_SPOTIFY_ID_OPT, '' ) : '',
		'secret_opt'    => defined( 'SN_SPOTIFY_SECRET_OPT' ) ? (string) get_option( SN_SPOTIFY_SECRET_OPT, '' ) : '',
		'spotify_on'    => function_exists( 'sn_spotify_config' ) && (bool) sn_spotify_config(),
		'profile_id'    => function_exists( 'sn_muso_profile_id' ) ? (string) sn_muso_profile_id() : '',
		'featured'      => $featured,
		'featured_url'  => ! empty( $featured['open_url'] ) ? (string) $featured['open_url'] : '',
	);
}

/**
 * The hidden pair both classic forms carry: `tab=content` / `sub=music`, from
 * before v10.46.0 moved the leaf to Connections. Kept verbatim: the window's
 * shared pipeline runs a submission's tab/sub through
 * sn_admin_post_redirect_target(), whose move table lands `content/music` on
 * `connections/music` — the same resolver the classic PRG redirect uses.
 *
 * @return array<string,string>
 */
function music_hidden() {
	return array( 'tab' => 'content', 'sub' => 'music' );
}

/**
 * The status box: which of the classic's four states the sync is in.
 *
 * @param array<string,mixed> $s From music_state().
 * @return string
 */
function music_status_html( array $s ) {
	$count = (int) $s['count'];
	if ( $count > 0 && '' === $s['last_error'] ) {
		$kind  = 'ok';
		$title = __( 'Synced', 'signal-and-noise-tools' );
		$pill  = __( 'Synced', 'signal-and-noise-tools' );
		/* translators: %d: cached releases */
		$body = sprintf( __( '%d release(s) cached. The /music timeline + MusicAlbum schema are live.', 'signal-and-noise-tools' ), $count );
	} elseif ( $count > 0 ) {
		$kind  = 'warn';
		$title = __( 'Showing last-good data', 'signal-and-noise-tools' );
		$pill  = __( 'Stale', 'signal-and-noise-tools' );
		/* translators: %d: cached releases */
		$body = sprintf( __( '%d release(s) still cached, but the last sync failed. The page never blanks — check the error and re-sync from the status panel on the right (below on narrow screens).', 'signal-and-noise-tools' ), $count );
	} elseif ( '' !== $s['last_error'] ) {
		$kind  = 'err';
		$title = __( 'Sync failed — no data yet', 'signal-and-noise-tools' );
		$pill  = __( 'Failed', 'signal-and-noise-tools' );
		$body  = __( 'The first sync hasn’t succeeded. The /music page falls back to its static content.', 'signal-and-noise-tools' );
	} else {
		$kind  = 'warn';
		$title = __( 'Not yet synced', 'signal-and-noise-tools' );
		$pill  = __( 'Pending', 'signal-and-noise-tools' );
		$body  = __( 'Hit “Sync now” in the status panel on the right (below on narrow screens) to populate the discography. The daily cron will keep it fresh after that.', 'signal-and-noise-tools' );
	}
	return \snt_kit_notice( $kind, '<b>' . \snt_kit_esc( $title ) . '</b> ' . \snt_kit_badge( $kind, $pill ) . '<br>' . \snt_kit_esc( $body ) );
}

/**
 * The credentials form: the three fieldsets as sections inside ONE form
 * posting `music_save` through the shared handler table, the submit labelled
 * as the classic button is.
 *
 * @param array<string,mixed> $s From music_state().
 * @return string
 */
function music_form_html( array $s ) {
	return \snt_kit_form(
		'music_save',
		music_spotify_section( $s ) . music_muso_section( $s ) . music_featured_section( $s ),
		array( 'submit' => __( 'Save settings', 'signal-and-noise-tools' ), 'hidden' => music_hidden() )
	);
}

/**
 * The leaf: the main column (intro, status box, the form), then the rail
 * (Status facts, Sync now) — the classic shell's two columns as the app's
 * column grid, the rail keeping its landmark name.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_connections_music( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$s = music_state();
	return '<div class="snt-cols">'
		. '<section class="snt-col">'
		. music_intro_html()
		. music_status_html( $s )
		. music_form_html( $s )
		. '</section>'
		. '<aside class="snt-col" aria-label="' . \snt_kit_esc( __( 'Sync status', 'signal-and-noise-tools' ) ) . '">'
		. music_rail_status_html( $s )
		. music_sync_html()
		. '</aside>'
		. '</div>';
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/music'] = __NAMESPACE__ . '\\paint_connections_music';
		return $painters;
	}
);
