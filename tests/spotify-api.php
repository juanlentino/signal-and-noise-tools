<?php
/**
 * Tests for inc/spotify-api.php — the minimal Spotify client that resolves a
 * Muso-supplied track Spotify id to its ALBUM (id / url / type / artwork / year).
 *
 * Under the revised architecture Spotify is NOT the credit source and NOT a
 * fuzzy matcher: Muso hands us each track's exact spotifyId, so we just call
 *   GET /v1/tracks/{id} → .album
 * to lift the embed/schema from one track up to the album it belongs to. ~11
 * calls per sync (one representative track per album), client-credentials auth.
 *
 * Standalone CLI fixture: stubs the token POST + the track GET and drives them
 * with tests/fixtures/spotify-track.json (a shape-faithful /v1/tracks/{id}
 * response keyed on a REAL Muso track id; synthesized to the documented Spotify
 * Web API shape since this session has no live credentials). Asserts (1) config
 * resolution + dormancy when unset, (2) the bearer token is cached (ONE POST
 * across repeated calls), and (3) album resolution returns the album fields and
 * fails soft (null) on 404 / no token.
 *
 * @since plugin v4.13.0
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
$GLOBALS['__trans']   = array();
$GLOBALS['__post_calls'] = 0; // token requests
$GLOBALS['__get_calls']  = 0; // track requests
// 'track_mode': 'ok' → return the fixture; 'http_404' → not found.
$GLOBALS['__spotify'] = array( 'track_mode' => 'ok', 'token_mode' => 'ok' );

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
function get_transient( $k ) {
	return array_key_exists( $k, $GLOBALS['__trans'] ) ? $GLOBALS['__trans'][ $k ] : false;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['__trans'][ $k ] = $v;
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['__trans'][ $k ] );
	return true;
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__post_calls']++;
	if ( 'wp_error' === $GLOBALS['__spotify']['token_mode'] ) {
		return new WP_Error( 'http_request_failed', 'connection refused' );
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array( 'access_token' => 'BQ-test-token', 'token_type' => 'Bearer', 'expires_in' => 3600 ) ),
	);
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__get_calls']++;
	if ( 'http_404' === $GLOBALS['__spotify']['track_mode'] ) {
		return array( 'response' => array( 'code' => 404 ), 'body' => '{"error":{"status":404,"message":"non existing id"}}' );
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => file_get_contents( __DIR__ . '/fixtures/spotify-track.json' ),
	);
}
function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0;
}
function wp_remote_retrieve_body( $r ) {
	return is_array( $r ) ? ( $r['body'] ?? '' ) : '';
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $o = 0 ) {
		return json_encode( $d, $o );
	}
}

require __DIR__ . '/../inc/spotify-api.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "Spotify album-resolver suite — plugin v4.13.0\n\n";

// ── CONFIG: dormant until id+secret present ──────────────────────────
ok( sn_spotify_config() === null, 'config: null when neither id nor secret set (feature dormant)' );
$GLOBALS['__options']['sn_spotify_client_id'] = 'cid';
ok( sn_spotify_config() === null, 'config: null with id but no secret' );
$GLOBALS['__options']['sn_spotify_client_secret'] = 'csecret';
$cfg = sn_spotify_config();
ok( is_array( $cfg ) && $cfg['id'] === 'cid' && $cfg['secret'] === 'csecret', 'config: resolves id + secret from options' );

// ── TOKEN: client-credentials, cached (one POST across calls) ────────
$GLOBALS['__post_calls'] = 0;
$t1 = sn_spotify_token();
$t2 = sn_spotify_token();
ok( $t1 === 'BQ-test-token' && $t2 === $t1, 'token: returns the bearer token' );
ok( $GLOBALS['__post_calls'] === 1, 'token: cached — only ONE token POST across repeated calls' );

// ── ALBUM RESOLUTION from a track id ─────────────────────────────────
$GLOBALS['__spotify']['track_mode'] = 'ok';
$album = sn_spotify_album_for_track( '6MuumbyTsu4CLaniAN0lBW' );
ok( is_array( $album ), 'album: resolves a track id to its album' );
ok( ( $album['spotify_id'] ?? '' ) === '7aB9cD2eF4gH6iJ8kL0mN1', 'album: spotify_id is the ALBUM id (not the track id)' );
ok( strpos( (string) ( $album['spotify_url'] ?? '' ), '/album/7aB9cD2eF4gH6iJ8kL0mN1' ) !== false, 'album: spotify_url is the album open.spotify.com link' );
ok( ( $album['type'] ?? '' ) === 'album', 'album: type from album.album_type' );
ok( (int) ( $album['year'] ?? 0 ) === 2025, 'album: year parsed from album.release_date' );
ok( strpos( (string) ( $album['image'] ?? '' ), 'i.scdn.co' ) !== false, 'album: image from album.images[0].url' );

// ── FAIL SOFT: 404 → null (album just keeps its Muso-only fields) ────
$GLOBALS['__spotify']['track_mode'] = 'http_404';
ok( sn_spotify_album_for_track( 'bogus' ) === null, 'album: 404 → null (sync keeps Muso artwork, no embed)' );

// Empty track id never hits the network.
$GLOBALS['__get_calls'] = 0;
ok( sn_spotify_album_for_track( '' ) === null, 'album: empty track id → null' );
ok( $GLOBALS['__get_calls'] === 0, 'album: empty track id makes no request' );

// ── DORMANT: unconfigured → no token, no resolution ──────────────────
$GLOBALS['__options'] = array();      // clear creds
$GLOBALS['__trans']   = array();      // clear cached token
$GLOBALS['__post_calls'] = 0;
ok( sn_spotify_token() === '', 'token: "" when unconfigured' );
ok( $GLOBALS['__post_calls'] === 0, 'token: unconfigured → no POST attempted' );
ok( sn_spotify_album_for_track( '6MuumbyTsu4CLaniAN0lBW' ) === null, 'album: unconfigured → null (no token)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
