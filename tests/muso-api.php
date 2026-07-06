<?php
/**
 * Tests for inc/muso-api.php — the (credential-free) Muso.AI public credits
 * client + the Muso→partial-entry album grouper.
 *
 * Data source: the unauthenticated public endpoint the credits SPA calls,
 *   GET https://api2.muso.ai/api/v4/profile/<id>/credits?limit=&offset=
 * (no x-api-key; browser-ish headers). Returns
 *   { result, code, data:{ totalCount, limit, offset, hasMoreToLoad, items[] } }.
 *
 * Standalone CLI fixture: stubs wp_remote_*, drives them with the REAL captured
 * response (tests/fixtures/muso-credits.json — 60 tracks / 11 albums), and
 * asserts (1) profile-id resolution (constant > option > default), (2) paginated
 * fetch merges pages + fails closed (WP_Error) on any HTTP/transport error, and
 * (3) sn_muso_albums_from_credits() groups the raw track-credits BY album.id into
 * partial store entries (union of roles, primary artist, year, Muso artwork, a
 * representative track spotifyId for later Spotify album resolution).
 *
 * The album grouper is the ONLY place that parses Muso's field names, so it is
 * exercised against the real fixture verbatim — no blind parsing.
 *
 * @since plugin v4.13.0
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );

// ─── Mutable transport state ──────────────────────────────────────────
// $GLOBALS['__http'] drives the wp_remote_get stub. 'mode':
//   'fixture'  → return the real muso-credits.json on every call
//   'pages'    → return $GLOBALS['__http']['pages'][offset] (multi-page test)
//   'wp_error' → return a WP_Error (transport failure)
//   'http_500' → return a 500 response
$GLOBALS['__http']    = array( 'mode' => 'fixture' );
$GLOBALS['__options'] = array();
$GLOBALS['__trans']   = array();

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['__options'][ $name ] );
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
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return is_string( $s ) ? trim( strip_tags( $s ) ) : '';
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return is_string( $url ) ? trim( $url ) : '';
	}
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// Count outbound requests so the pagination assertions can prove one-call-per-page.
$GLOBALS['__http_calls'] = 0;
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__http_calls']++;
	$GLOBALS['__last_args'] = $args;
	$mode = $GLOBALS['__http']['mode'];
	if ( 'wp_error' === $mode ) {
		return new WP_Error( 'http_request_failed', 'cURL error 7: Connection refused' );
	}
	if ( 'http_500' === $mode ) {
		return array( 'response' => array( 'code' => 500 ), 'body' => 'upstream error' );
	}
	if ( 'pages' === $mode ) {
		// Pull offset out of the query string and serve that synthetic page.
		$offset = 0;
		if ( preg_match( '/[?&]offset=(\d+)/', $url, $m ) ) {
			$offset = (int) $m[1];
		}
		$page = $GLOBALS['__http']['pages'][ $offset ] ?? array( 'data' => array( 'items' => array(), 'hasMoreToLoad' => false ) );
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $page ) );
	}
	// 'fixture'
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => file_get_contents( __DIR__ . '/fixtures/muso-credits.json' ),
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

require __DIR__ . '/../inc/muso-api.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "Muso public-API client suite — plugin v4.13.0\n\n";

// ── PROFILE ID resolution: default → option → constant ───────────────
ok( sn_muso_profile_id() === '50d6a7c0-2d7b-4557-94b6-c472fa949df2', 'profile id: defaults to Juan\'s' );
$GLOBALS['__options']['sn_muso_profile_id'] = 'option-pid';
ok( sn_muso_profile_id() === 'option-pid', 'profile id: option overrides default' );
unset( $GLOBALS['__options']['sn_muso_profile_id'] );

// ── FETCH (happy path against the REAL fixture) ──────────────────────
$GLOBALS['__http']['mode'] = 'fixture';
$GLOBALS['__http_calls']   = 0;
$items = sn_muso_fetch_credits();
ok( is_array( $items ) && ! is_wp_error( $items ), 'fetch: returns an array on 200' );
ok( count( $items ) === 60, 'fetch: returns all 60 track-credits' );
ok( isset( $items[0]['track']['spotifyId'] ) && '' !== $items[0]['track']['spotifyId'], 'fetch: items carry track.spotifyId' );
ok( $GLOBALS['__http_calls'] === 1, 'fetch: single page (hasMoreToLoad:false) → one request' );
// Outbound-hardening convention (v8.7.1): a credential-carrying call must forbid
// redirects so WP never re-sends the Muso key to a 3xx target. Pins the convention.
ok( 0 === ( $GLOBALS['__last_args']['redirection'] ?? -1 ), 'fetch: request disables redirects (no Muso key forward on a 3xx)' );

// ── FETCH (pagination merges across pages) ───────────────────────────
$GLOBALS['__http']['mode']  = 'pages';
$GLOBALS['__http']['pages'] = array(
	0 => array( 'data' => array( 'items' => array( array( 'k' => 'a' ), array( 'k' => 'b' ) ), 'hasMoreToLoad' => true ) ),
	100 => array( 'data' => array( 'items' => array( array( 'k' => 'c' ) ), 'hasMoreToLoad' => false ) ),
);
$GLOBALS['__http_calls'] = 0;
$merged = sn_muso_fetch_credits();
ok( is_array( $merged ) && count( $merged ) === 3, 'fetch: merges items across paginated pages' );
ok( $GLOBALS['__http_calls'] === 2, 'fetch: stops when hasMoreToLoad:false (2 pages → 2 requests)' );

// ── FETCH fails closed on transport + HTTP errors ────────────────────
$GLOBALS['__http']['mode'] = 'wp_error';
$err = sn_muso_fetch_credits();
ok( is_wp_error( $err ), 'fetch: transport failure → WP_Error (fails closed, never partial)' );
ok( get_transient( 'sn_muso_last_error' ) !== false, 'fetch: records last_error transient on failure' );

$GLOBALS['__http']['mode'] = 'http_500';
ok( is_wp_error( sn_muso_fetch_credits() ), 'fetch: HTTP 500 → WP_Error' );

// A successful fetch clears the stale error.
$GLOBALS['__http']['mode'] = 'fixture';
sn_muso_fetch_credits();
ok( get_transient( 'sn_muso_last_error' ) === false, 'fetch: success clears the stale error transient' );

// ── ALBUM GROUPER against the REAL fixture (60 tracks → 11 albums) ────
$real    = json_decode( file_get_contents( __DIR__ . '/fixtures/muso-credits.json' ), true );
$raw     = $real['data']['items'];
$albums  = sn_muso_albums_from_credits( $raw );
ok( is_array( $albums ) && count( $albums ) === 10, 'grouper: 60 credits → 10 distinct releases (11 album.ids; the Fin del Mundo pair deduped)' );

// Index by Muso album id for targeted assertions.
$by_id = array();
foreach ( $albums as $a ) {
	$by_id[ $a['id'] ] = $a;
}

// ARGIRIA: 12 tracks, artist Cande Schulman, released 2025, Spotify artwork.
$argiria = $by_id['3a543960-ce8e-467a-b066-ac70d48ffc60'] ?? array();
ok( ( $argiria['title'] ?? '' ) === 'ARGIRIA', 'grouper: album title from album.title' );
ok( ( $argiria['artist'] ?? '' ) === 'Cande Schulman', 'grouper: artist from artists[0].name' );
ok( (int) ( $argiria['year'] ?? 0 ) === 2025, 'grouper: year parsed from releaseDate' );
ok( ( $argiria['date'] ?? '' ) === '2025-04-18', 'grouper: captures the FULL release date (YYYY-MM-DD), not just the year' );
ok( strpos( (string) ( $argiria['image'] ?? '' ), 'i.scdn.co' ) !== false, 'grouper: image from Muso album.avatarUrl_640_640' );
ok( strpos( (string) ( $argiria['muso_url'] ?? '' ), '3a543960-ce8e-467a-b066-ac70d48ffc60' ) !== false, 'grouper: muso_url deep-links the album id' );
ok( ! empty( $argiria['spotify_track_id'] ), 'grouper: carries a representative track spotifyId for album resolution' );
ok( is_array( $argiria['roles'] ?? null ) && ! empty( $argiria['roles'] ), 'grouper: roles is the non-empty union of per-track credits' );

// Roles are a DEDUPED union across that album's tracks.
ok( count( $argiria['roles'] ) === count( array_unique( $argiria['roles'] ) ), 'grouper: roles union is deduped' );

// Two Muso album records share title "Fin del Mundo" + artist Richter (a 1-track
// 2008 single + a 10-track 2007 album) — the SAME record to a visitor. Dedup
// collapses them to ONE: the fuller release (10-track album), the earliest year,
// and the union of roles. Distinct TITLES (Transforma2 / Transformador / …) stay.
$fdm = array_values(
	array_filter(
		$albums,
		function ( $a ) {
			return 'Fin del Mundo' === $a['title'];
		}
	)
);
ok( count( $fdm ) === 1, 'dedup: same title+artist albums collapse to ONE entry' );
ok( (int) ( $fdm[0]['year'] ?? 0 ) === 2007, 'dedup: keeps the earliest year (2007 album, not the 2008 single)' );
ok( ( $fdm[0]['id'] ?? '' ) === '4a4e36ed-1dcc-4c36-bf9c-affd39dd57c4', 'dedup: keeps the fuller release id (the 10-track album)' );
$keys = array_map(
	function ( $a ) {
		return strtolower( $a['title'] . '|' . $a['artist'] );
	},
	$albums
);
ok( count( $keys ) === count( array_unique( $keys ) ), 'dedup: no two entries share a title+artist' );

// ── B1: per-track liner notes (tracks[] built from the real fixture) ──
ok( is_array( $argiria['tracks'] ?? null ) && ! empty( $argiria['tracks'] ), 'tracks: ARGIRIA carries a non-empty tracks[]' );
ok( count( $argiria['tracks'] ) === 12, 'tracks: ARGIRIA keeps its 12 distinct tracks' );
$t0 = $argiria['tracks'][0];
ok( isset( $t0['title'], $t0['roles'], $t0['preview_url'], $t0['spotify_id'] ), 'tracks: each record has title/roles/preview_url/spotify_id' );

// A named track with its exact PER-TRACK credits + preview + id.
$chf = null;
foreach ( $argiria['tracks'] as $t ) {
	if ( 'Cuando Haga Frío' === $t['title'] ) { $chf = $t; break; }
}
ok( null !== $chf, 'tracks: a named track (Cuando Haga Frío) is present' );
ok( $chf['roles'] === array( 'Mastering', 'Engineer' ), 'tracks: roles are the TRACK credits, not the flattened album union' );
ok( strpos( (string) $chf['preview_url'], 'p.scdn.co/mp3-preview' ) !== false, 'tracks: carries the 30-sec preview URL (cookieless <audio> source)' );
ok( $chf['spotify_id'] === '6MuumbyTsu4CLaniAN0lBW', 'tracks: carries the per-track Spotify id' );

// Per-track granularity proven across releases: "Moment of Supreme Clarity"
// (Juan's own single) credits Composer/Synthesizer — a richer set than ARGIRIA's
// tracks — so tracks[] preserves credits PER recording.
$mosc = $by_id['d24dbe47-4857-45fc-9b0b-97fb2b6ea61f'] ?? array();
ok( ! empty( $mosc['tracks'] ) && in_array( 'Composer', $mosc['tracks'][0]['roles'], true ), 'tracks: a distinct release keeps its own per-track credits (Composer)' );

// Dedup MERGES tracks (never drops them): the collapsed Fin del Mundo entry keeps tracks.
ok( ! empty( $fdm[0]['tracks'] ), 'tracks: survive the title+artist dedup merge (carried over, not lost)' );

// The internal keyed map never leaks into the partial entry.
ok( ! isset( $argiria['_tracks'] ), 'tracks: internal _tracks map stripped (only the flat tracks[] persists)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
