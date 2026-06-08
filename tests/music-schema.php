<?php
/**
 * Tests for inc/seo-schema-music.php — per-release MusicAlbum / MusicRecording
 * JSON-LD emitted on /music, with Juan as schema.org `producer` (a Person ref,
 * NOT a MusicGroup) and the primary artist as `byArtist`.
 *
 * Standalone CLI fixture: seeds the discography store, stubs the WP route +
 * encode helpers, and asserts the built graph node shape + that an empty store
 * emits nothing. Mirrors tests/seo-schema.php. The producer @id MUST equal the
 * canonical Person @id (home_url('/') . '#/schema/Person') so it references the
 * SAME node the existing Person schema emits.
 *
 * @since plugin v4.13.0
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── Mutable test state ───────────────────────────────────────────────
$GLOBALS['__ms'] = array(
	'store'   => null,  // value returned by sn_discography_get()
	'is_music' => true, // is_page('music')
);

// ─── In-memory store + WP stubs ───────────────────────────────────────
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'is_page' ) ) {
	function is_page( $page = '' ) {
		return ( 'music' === $page ) ? (bool) $GLOBALS['__ms']['is_music'] : false;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return is_string( $url ) ? trim( $url ) : ''; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

// sn_discography_get() is provided by the store module; we drive it with $GLOBALS['__ms']['store'].
$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) { return is_string( $url ) ? trim( $url ) : ''; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) {
		$s = strtolower( (string) $s );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
		return trim( $s, '-' );
	}
}

require __DIR__ . '/../inc/discography-store.php';
require __DIR__ . '/../inc/seo-schema-music.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── 1 album entry → one MusicAlbum node, Person as producer ──────────
sn_discography_set( array(
	sn_discography_normalize_entry( array(
		'title'       => 'Heliotrope',
		'artist'      => 'The Field',
		'roles'       => array( 'Producer', 'Mixing' ),
		'year'        => 2021,
		'type'        => 'album',
		'image'       => 'https://i.scdn.co/image/abc.jpg',
		'spotify_url' => 'https://open.spotify.com/album/xyz',
		'muso_url'    => 'https://credits.muso.ai/album/123',
	) ),
), 1700000000, '' );

$json = sn_music_schema_jsonld();
ok( is_string( $json ) && '' !== $json, 'emit: non-empty JSON-LD when store has entries' );
ok( strpos( $json, '<script type="application/ld+json">' ) !== false, 'emit: wraps in ld+json script tag' );

// Decode the JSON payload out of the script tag.
$inner   = preg_replace( '#^.*?<script[^>]*>(.*)</script>.*$#s', '$1', $json );
$payload = json_decode( $inner, true );
ok( is_array( $payload ), 'emit: payload decodes as JSON' );

$graph = $payload['@graph'] ?? array();
ok( count( $graph ) === 1, 'graph: one node for one entry' );
$node = $graph[0] ?? array();

ok( ( $node['@type'] ?? null ) === 'MusicAlbum', 'node: @type === MusicAlbum (album type)' );
ok( ( $node['name'] ?? null ) === 'Heliotrope', 'node: name === release title' );
ok( ( $node['byArtist']['@type'] ?? null ) === 'MusicGroup', 'node: byArtist is a MusicGroup' );
ok( ( $node['byArtist']['name'] ?? null ) === 'The Field', 'node: byArtist.name === primary artist' );

// CRITICAL: producer references the SAME canonical Person @id (not a new node).
$expected_pid = home_url( '/' ) . '#/schema/Person';
ok( ( $node['producer']['@id'] ?? null ) === $expected_pid, 'node: producer.@id === canonical Person @id' );
ok( ! isset( $node['producer']['@type'] ) || $node['producer']['@type'] !== 'MusicGroup', 'node: producer is a Person ref, NOT a MusicGroup' );

ok( (string) ( $node['datePublished'] ?? null ) === '2021', 'node: datePublished === year' );
ok( ( $node['image'] ?? null ) === 'https://i.scdn.co/image/abc.jpg', 'node: image from entry' );
ok( in_array( 'https://open.spotify.com/album/xyz', (array) ( $node['sameAs'] ?? array() ), true ), 'node: sameAs includes spotify_url' );
ok( in_array( 'https://credits.muso.ai/album/123', (array) ( $node['sameAs'] ?? array() ), true ), 'node: sameAs includes muso_url' );

// ── track type → MusicRecording ──────────────────────────────────────
sn_discography_set( array(
	sn_discography_normalize_entry( array(
		'title'  => 'A Single',
		'artist' => 'Someone',
		'year'   => 2019,
		'type'   => 'track',
	) ),
), 1700000000, '' );
$json2    = sn_music_schema_jsonld();
$inner2   = preg_replace( '#^.*?<script[^>]*>(.*)</script>.*$#s', '$1', $json2 );
$payload2 = json_decode( $inner2, true );
$node2    = $payload2['@graph'][0] ?? array();
ok( ( $node2['@type'] ?? null ) === 'MusicRecording', 'node: track type → MusicRecording' );

// ── empty store → emits nothing ──────────────────────────────────────
$GLOBALS['__options'] = array();
ok( sn_music_schema_jsonld() === '', 'emit: empty store → emits nothing' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
