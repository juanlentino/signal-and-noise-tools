<?php
/**
 * Tests for inc/music-featured.php — the settings-driven "featured release"
 * (the one Spotify player at the top of /music, set from Monitoring → Music).
 *
 * Parses a pasted Spotify URL/URI into {type,id}, stores it, and exposes it over
 * the standalone-safe `sn_music_featured` filter the theme's [sn_music_featured]
 * shortcode reads. Standalone CLI fixture: stubs the option store, exercises the
 * parser across URL/URI/locale/query-string/invalid forms, and asserts the
 * accessor + filter shapes.
 *
 * @since plugin v4.14.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );
define( 'SN_MUSIC_FEATURED_TEST', true ); // suppress the add_filter wiring.

$GLOBALS['__options'] = array();
function get_option( $n, $d = false ) {
	return array_key_exists( $n, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $n ] : $d;
}
function update_option( $n, $v, $a = null ) {
	$GLOBALS['__options'][ $n ] = $v;
	return true;
}
function delete_option( $n ) {
	unset( $GLOBALS['__options'][ $n ] );
	return true;
}

// B4 (v6.16.0): stubs for the auto-derive path. The derive reads the discography
// store and honors the `sn_music_featured_autoderive` opt-out filter.
function apply_filters( $tag, $value ) {
	if ( 'sn_music_featured_autoderive' === $tag ) {
		return array_key_exists( '__autoderive', $GLOBALS ) ? $GLOBALS['__autoderive'] : $value;
	}
	return $value;
}
function sn_discography_get() {
	return isset( $GLOBALS['__discog'] ) ? $GLOBALS['__discog'] : array( 'entries' => array() );
}

require __DIR__ . '/../inc/music-featured.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "Music featured-release suite — plugin v4.14.0\n\n";

$TRACK = '6MuumbyTsu4CLaniAN0lBW';   // 22-char base62
$ALBUM = '4m2880jivSbbyEGAKfITCa';
$PLIST = '37i9dQZF1DXcBWIGoYBM5M';

// ── PARSER ────────────────────────────────────────────────────────────
$p = sn_music_featured_parse( 'https://open.spotify.com/track/' . $TRACK );
ok( is_array( $p ) && $p['type'] === 'track' && $p['id'] === $TRACK, 'parse: track URL → {track,id}' );

$p = sn_music_featured_parse( 'https://open.spotify.com/album/' . $ALBUM );
ok( is_array( $p ) && $p['type'] === 'album' && $p['id'] === $ALBUM, 'parse: album URL → {album,id}' );

$p = sn_music_featured_parse( 'https://open.spotify.com/playlist/' . $PLIST );
ok( is_array( $p ) && $p['type'] === 'playlist' && $p['id'] === $PLIST, 'parse: playlist URL → {playlist,id}' );

// Query string (?si=…) is stripped.
$p = sn_music_featured_parse( 'https://open.spotify.com/track/' . $TRACK . '?si=abc123&utm_source=copy' );
ok( is_array( $p ) && $p['id'] === $TRACK, 'parse: ?si=… query stripped' );

// Locale path (/intl-es/).
$p = sn_music_featured_parse( 'https://open.spotify.com/intl-es/album/' . $ALBUM );
ok( is_array( $p ) && $p['type'] === 'album' && $p['id'] === $ALBUM, 'parse: /intl-xx/ locale path handled' );

// Spotify URI form.
$p = sn_music_featured_parse( 'spotify:track:' . $TRACK );
ok( is_array( $p ) && $p['type'] === 'track' && $p['id'] === $TRACK, 'parse: spotify:track:… URI form' );

// Surrounding whitespace.
$p = sn_music_featured_parse( '  https://open.spotify.com/track/' . $TRACK . "  \n" );
ok( is_array( $p ) && $p['id'] === $TRACK, 'parse: trims surrounding whitespace' );

// ── REJECTIONS ────────────────────────────────────────────────────────
ok( sn_music_featured_parse( '' ) === null, 'parse: empty → null' );
ok( sn_music_featured_parse( 'just some text' ) === null, 'parse: non-URL → null' );
ok( sn_music_featured_parse( 'https://example.com/track/' . $TRACK ) === null, 'parse: non-Spotify host → null' );
ok( sn_music_featured_parse( 'https://open.spotify.com/user/someone' ) === null, 'parse: unsupported type (user) → null' );
ok( sn_music_featured_parse( 'https://open.spotify.com/track/short' ) === null, 'parse: too-short id → null' );

// ── ACCESSOR + FILTER ─────────────────────────────────────────────────
ok( sn_music_featured_get() === array(), 'get: unset → empty array' );

$GLOBALS['__options'][ SN_MUSIC_FEATURED_OPT ] = array( 'type' => 'album', 'id' => $ALBUM );
$g = sn_music_featured_get();
ok( $g['type'] === 'album' && $g['id'] === $ALBUM, 'get: returns stored type + id' );
ok( $g['embed_url'] === 'https://open.spotify.com/embed/album/' . $ALBUM, 'get: builds the embed_url' );
ok( $g['open_url'] === 'https://open.spotify.com/album/' . $ALBUM, 'get: builds the open_url' );

// Malformed stored value → empty (defensive).
$GLOBALS['__options'][ SN_MUSIC_FEATURED_OPT ] = array( 'type' => 'album' ); // missing id
ok( sn_music_featured_get() === array(), 'get: malformed stored value → empty' );

// Filter returns the accessor result.
$GLOBALS['__options'][ SN_MUSIC_FEATURED_OPT ] = array( 'type' => 'track', 'id' => $TRACK );
$f = sn_music_featured_filter( array() );
ok( is_array( $f ) && $f['id'] === $TRACK && isset( $f['embed_url'] ), 'filter: sn_music_featured returns the featured config' );

// ── B4: AUTO-DERIVE THE FEATURED PLAYER (v6.16.0) ──────────────────────
// When the owner hasn't set a featured release, the /music hero falls back to
// the newest playable discography release. Manual setting stays authoritative;
// the derived record is flagged auto => true; opt-out via a filter.

$ALBUM2 = '1ATL5GLyefJaxhQzSPVrLX'; // a second 22-char album id (older release).

// Reset to the unset state for the derive path.
delete_option( SN_MUSIC_FEATURED_OPT );
unset( $GLOBALS['__autoderive'], $GLOBALS['__discog'] );

// Manual record carries auto => false (provenance flag, symmetric with derived).
$GLOBALS['__options'][ SN_MUSIC_FEATURED_OPT ] = array( 'type' => 'album', 'id' => $ALBUM );
ok( sn_music_featured_get()['auto'] === false, 'get: manual record flagged auto => false' );
delete_option( SN_MUSIC_FEATURED_OPT );

// from_entry: a playable spotify_id → album record (auto), ignoring album_type.
$r = sn_music_featured_from_entry( array( 'spotify_id' => $ALBUM, 'type' => 'single' ) );
ok( is_array( $r ) && $r['type'] === 'album' && $r['id'] === $ALBUM && $r['auto'] === true, 'from_entry: spotify_id → album record (auto), album_type ignored' );

// from_entry: no spotify_id but a valid spotify_url → parsed fallback.
$r = sn_music_featured_from_entry( array( 'spotify_id' => '', 'spotify_url' => 'https://open.spotify.com/album/' . $ALBUM2 ) );
ok( is_array( $r ) && $r['type'] === 'album' && $r['id'] === $ALBUM2 && $r['auto'] === true, 'from_entry: spotify_url fallback parsed' );

// from_entry: no playable data / bad shapes → empty.
ok( sn_music_featured_from_entry( array( 'spotify_id' => '', 'spotify_url' => '' ) ) === array(), 'from_entry: no spotify data → empty' );
ok( sn_music_featured_from_entry( 'nope' ) === array(), 'from_entry: non-array → empty' );
ok( sn_music_featured_from_entry( array( 'spotify_id' => 'tooshort' ) ) === array(), 'from_entry: invalid id, no url → empty' );

// derive: empty store → empty.
$GLOBALS['__discog'] = array( 'entries' => array() );
ok( sn_music_featured_derive() === array(), 'derive: empty store → empty' );

// derive: store present but nothing playable → empty.
$GLOBALS['__discog'] = array( 'entries' => array( array( 'year' => 2025, 'spotify_id' => '', 'spotify_url' => '' ) ) );
ok( sn_music_featured_derive() === array(), 'derive: no playable entry → empty' );

// derive: skips the non-playable newest, returns the newest PLAYABLE (year-desc).
$GLOBALS['__discog'] = array(
	'entries' => array(
		array( 'year' => 2025, 'spotify_id' => '', 'spotify_url' => '' ), // newest, not playable
		array( 'year' => 2024, 'spotify_id' => $ALBUM ),                  // newest playable
		array( 'year' => 2023, 'spotify_id' => $ALBUM2 ),                 // older playable
	),
);
$d = sn_music_featured_derive();
ok( is_array( $d ) && $d['id'] === $ALBUM && $d['type'] === 'album' && $d['auto'] === true, 'derive: skips non-playable, returns newest playable' );

// derive: opt-out via the sn_music_featured_autoderive filter → empty.
$GLOBALS['__autoderive'] = false;
ok( sn_music_featured_derive() === array(), 'derive: autoderive filter false → empty' );
unset( $GLOBALS['__autoderive'] );

// filter: UNSET manual + playable store → the derived hero (the real /music seam).
$f = sn_music_featured_filter( array() );
ok( is_array( $f ) && $f['id'] === $ALBUM && ! empty( $f['embed_url'] ) && $f['auto'] === true, 'filter: unset → auto-derived hero (auto-flagged)' );

// filter: a manual setting stays AUTHORITATIVE over a playable store.
$GLOBALS['__options'][ SN_MUSIC_FEATURED_OPT ] = array( 'type' => 'track', 'id' => $TRACK );
$f = sn_music_featured_filter( array() );
ok( is_array( $f ) && $f['id'] === $TRACK && $f['type'] === 'track' && $f['auto'] === false, 'filter: manual authoritative (not auto) when set' );
delete_option( SN_MUSIC_FEATURED_OPT );

// get: stays manual-only — unset → empty even with a playable store (no auto
// value leaks into the admin pre-fill field).
ok( sn_music_featured_get() === array(), 'get: manual-only — unset → empty despite playable store' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
