<?php
/**
 * Tests for inc/discography-sync.php — the cron orchestrator that turns Muso
 * credits + Spotify album resolution into the normalized store, and the
 * standalone-safe sn_discography_entries filter the theme reads.
 *
 * Standalone CLI fixture: stubs the Muso + Spotify boundary functions (their own
 * suites cover the real parsing) and the WP option store, then drives
 * sn_discography_run_sync() through the success + failure paths. Asserts:
 *   - success: Muso albums × Spotify resolution → normalized store entries; the
 *     matched album carries an embed (spotify_id), the unmatched one is KEPT with
 *     Muso-only fields; image prefers Muso artwork but falls back to Spotify;
 *     the internal spotify_track_id never leaks into the store.
 *   - last-good: a Muso WP_Error / empty result PRESERVES the prior entries and
 *     records last_error (the page never blanks on an API outage).
 *   - the sn_discography_entries filter returns the store's entries (the
 *     cross-package contract the theme's [sn_discography] consumes).
 *
 * @since plugin v4.13.0
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );
// Suppress the module's add_action/add_filter wiring — we call the fns directly.
define( 'SN_DISCOGRAPHY_SYNC_TEST', true );

$GLOBALS['__options'] = array();

class WP_Error {
	public $code;
	public $message;
	public function __construct( $c = '', $m = '' ) {
		$this->code    = $c;
		$this->message = $m;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}
function get_option( $n, $d = false ) {
	return array_key_exists( $n, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $n ] : $d;
}
function update_option( $n, $v, $a = null ) {
	$GLOBALS['__options'][ $n ] = $v;
	return true;
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return is_string( $s ) ? trim( strip_tags( $s ) ) : '';
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) {
		return is_string( $u ) ? trim( $u ) : '';
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) {
		$s = strtolower( (string) $s );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
		return trim( $s, '-' );
	}
}

// ── Stub the Muso + Spotify boundary (their own suites test the real code) ──
$GLOBALS['__muso']    = array( 'return' => array(), 'albums' => array() );
$GLOBALS['__sp_cfg']  = array( 'id' => 'cid', 'secret' => 'sec' ); // Spotify configured
$GLOBALS['__sp']      = array(); // track_id → resolution|null
$GLOBALS['__sp_calls'] = 0;

function sn_muso_fetch_credits( $pid = '' ) {
	return $GLOBALS['__muso']['return'];
}
function sn_muso_albums_from_credits( $items ) {
	return $GLOBALS['__muso']['albums'];
}
function sn_spotify_config() {
	return $GLOBALS['__sp_cfg'];
}
function sn_spotify_album_for_track( $id ) {
	$GLOBALS['__sp_calls']++;
	return $GLOBALS['__sp'][ $id ] ?? null;
}

require __DIR__ . '/../inc/discography-store.php';
require __DIR__ . '/../inc/discography-sync.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "Discography sync orchestrator suite — plugin v4.13.0\n\n";

// Three partial albums from the (stubbed) Muso grouper:
//   A — Muso artwork + a Spotify match            → embed + Muso artwork kept
//   B — Muso artwork + NO Spotify match           → kept, Muso-only, no embed
//   C — NO Muso artwork + a Spotify match         → Spotify artwork fallback
$GLOBALS['__muso']['albums'] = array(
	array( 'id' => 'al-A', 'title' => 'Album A', 'artist' => 'X', 'roles' => array( 'Producer' ), 'year' => 2024, 'image' => 'https://muso/A.jpg', 'muso_url' => 'https://credits.muso.ai/album/al-A', 'type' => '', 'spotify_track_id' => 'tA' ),
	array( 'id' => 'al-B', 'title' => 'Album B', 'artist' => 'Y', 'roles' => array( 'Mixing' ), 'year' => 2005, 'image' => 'https://muso/B.jpg', 'muso_url' => 'https://credits.muso.ai/album/al-B', 'type' => '', 'spotify_track_id' => 'tB' ),
	array( 'id' => 'al-C', 'title' => 'Album C', 'artist' => 'Z', 'roles' => array( 'Engineer' ), 'year' => 2018, 'image' => '', 'muso_url' => 'https://credits.muso.ai/album/al-C', 'type' => '', 'spotify_track_id' => 'tC' ),
);
$GLOBALS['__muso']['return'] = array( array( 'stub' => true ) ); // non-empty raw items
$GLOBALS['__sp'] = array(
	'tA' => array( 'spotify_id' => 'spA', 'spotify_url' => 'https://open.spotify.com/album/spA', 'type' => 'single', 'image' => 'https://scdn/A.jpg', 'year' => 2024 ),
	'tC' => array( 'spotify_id' => 'spC', 'spotify_url' => 'https://open.spotify.com/album/spC', 'type' => 'album', 'image' => 'https://scdn/C.jpg', 'year' => 2018 ),
	// 'tB' intentionally absent → null (no match).
);

$result = sn_discography_run_sync();
ok( true === $result, 'sync: returns true on success' );

$store = sn_discography_get();
ok( $store['count'] === 3, 'sync: all 3 albums stored (matched + unmatched kept)' );
ok( $store['last_error'] === '', 'sync: last_error empty on success' );
ok( $store['last_synced'] > 0, 'sync: records a sync timestamp' );

// Index by id.
$e = array();
foreach ( $store['entries'] as $row ) {
	$e[ $row['id'] ] = $row;
}

// A: matched → embed id set; Muso artwork PREFERRED over Spotify's.
ok( ( $e['al-A']['spotify_id'] ?? '' ) === 'spA', 'A: matched album carries the Spotify ALBUM id (embed)' );
ok( ( $e['al-A']['spotify_url'] ?? '' ) === 'https://open.spotify.com/album/spA', 'A: matched album carries the Spotify album url' );
ok( ( $e['al-A']['type'] ?? '' ) === 'single', 'A: Spotify album_type fills the empty Muso type' );
ok( ( $e['al-A']['image'] ?? '' ) === 'https://muso/A.jpg', 'A: Muso artwork preferred when present' );

// B: no match → KEPT, Muso-only, no embed.
ok( isset( $e['al-B'] ), 'B: unmatched album is KEPT (not dropped)' );
ok( ( $e['al-B']['spotify_id'] ?? 'x' ) === '', 'B: unmatched album has no embed id' );
ok( ( $e['al-B']['image'] ?? '' ) === 'https://muso/B.jpg', 'B: unmatched album keeps its Muso artwork' );

// C: matched, no Muso art → Spotify artwork fallback.
ok( ( $e['al-C']['image'] ?? '' ) === 'https://scdn/C.jpg', 'C: Spotify artwork fills empty Muso image' );

// Internal-only field must never leak into the store.
ok( ! isset( $e['al-A']['spotify_track_id'] ), 'store: internal spotify_track_id stripped before persist' );

// Sorted year-desc (store contract): 2024 (A), 2018 (C), 2005 (B).
ok( $store['entries'][0]['id'] === 'al-A' && $store['entries'][2]['id'] === 'al-B', 'sync: entries sorted year-desc by the store' );

// ── FILTER: the cross-package contract the theme reads ───────────────
$filtered = sn_discography_entries_filter( array() );
ok( is_array( $filtered ) && count( $filtered ) === 3, 'filter: sn_discography_entries returns the store entries' );

// ── LAST-GOOD: a Muso WP_Error preserves the prior store ─────────────
$GLOBALS['__muso']['return'] = new WP_Error( 'sn_muso_http', 'HTTP 503' );
$r2 = sn_discography_run_sync();
ok( false === $r2, 'sync: returns false when the source errors' );
$store2 = sn_discography_get();
ok( $store2['count'] === 3, 'last-good: prior 3 entries preserved on Muso error (page never blanks)' );
ok( $store2['last_error'] !== '', 'last-good: records last_error on failure' );

// ── LAST-GOOD: an empty Muso result also preserves ───────────────────
$GLOBALS['__muso']['return']  = array(); // no items
$GLOBALS['__muso']['albums']  = array();
$r3 = sn_discography_run_sync();
ok( false === $r3, 'sync: empty Muso result is a soft failure' );
ok( sn_discography_get()['count'] === 3, 'last-good: empty result also preserves prior entries' );

// ── SPOTIFY DORMANT: unconfigured → Muso-only, no resolution calls ───
$GLOBALS['__sp_cfg'] = null; // Spotify not configured
$GLOBALS['__muso']['return'] = array( array( 'stub' => true ) );
$GLOBALS['__muso']['albums'] = array(
	array( 'id' => 'al-D', 'title' => 'Album D', 'artist' => 'Q', 'roles' => array( 'Producer' ), 'year' => 2030, 'image' => 'https://muso/D.jpg', 'muso_url' => 'https://credits.muso.ai/album/al-D', 'type' => '', 'spotify_track_id' => 'tD' ),
);
$GLOBALS['__sp_calls'] = 0;
sn_discography_run_sync();
$d = sn_discography_get();
ok( $d['count'] === 1 && $d['entries'][0]['id'] === 'al-D', 'dormant: Muso-only sync still stores the album' );
ok( $d['entries'][0]['spotify_id'] === '' && $d['entries'][0]['image'] === 'https://muso/D.jpg', 'dormant: Muso artwork only, no embed' );
ok( $GLOBALS['__sp_calls'] === 0, 'dormant: Spotify unconfigured → zero resolution calls' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
