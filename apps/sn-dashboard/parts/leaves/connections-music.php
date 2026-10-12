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
 * 17.4.1 (#1573): the classic's two columns paired the 906px form beside the
 * 349px rail, leaving the rail's column a hole. 2026-10-12: two rows of
 * paired boxes (see paint_connections_music()).
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
 * The status notice: which of the four states the sync is in, in the words
 * the classic page says (sn_music_status_copy()).
 *
 * @param array<string,mixed> $s From music_state().
 * @return string
 */
function music_status_html( array $s ) {
	$c = \sn_music_status_copy( (int) $s['count'], (string) $s['last_error'] );
	return \snt_kit_notice( $c['kind'], '<b>' . \snt_kit_esc( $c['title'] ) . '</b> ' . \snt_kit_badge( $c['kind'], $c['pill'] ) . '<br>' . \snt_kit_esc( $c['body'] ) );
}

/**
 * The leaf (2026-10-12 audit: the settings form stood alone at its 760px
 * measure beside 540px of nothing, and Sync now was an empty box holding one
 * button). Two rows of paired boxes of comparable height, measured: Status
 * (with Sync now at its foot, 281px) beside the Featured release (217px);
 * then the two sources, the Muso.AI profile (198px) beside the read-only
 * Spotify facts (147px). Each form saves only its own field.
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
	return music_intro_html()
		. music_status_html( $s )
		. \snt_kit_grid( array( music_rail_status_html( $s ), music_featured_section( $s ) ), 290, 18, array( 'role' => 'complementary', 'aria-label' => __( 'Sync status', 'signal-and-noise-tools' ) ) )
		. \snt_kit_grid( array( music_muso_section( $s ), music_spotify_section( $s ) ) );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/music'] = __NAMESPACE__ . '\\paint_connections_music';
		return $painters;
	}
);
