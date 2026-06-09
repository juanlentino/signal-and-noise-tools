<?php
/**
 * Tests for inc/discography-store.php — the normalized, source-agnostic
 * discography cache (the store the schema emitter, theme display, and admin
 * status all read).
 *
 * Standalone CLI fixture: stubs the WP option store + sanitizers, requires the
 * real discography-store.php, and exercises empty-store defaults, entry
 * normalization (trim/cast/scalar-role-coercion/id-derivation), and the
 * year-descending sort + meta recompute on set(). Mirrors tests/settings-theme.php.
 *
 * @since plugin v4.13.0
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );

// In-memory option store + minimal WP stubs.
$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['__options'][ $name ] = $value;
	return true;
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
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

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require __DIR__ . '/../inc/discography-store.php';

// ── Empty store → safe defaults ──────────────────────────────────────
$s = sn_discography_get();
ok( is_array( $s['entries'] ) && $s['entries'] === array(), 'store: empty entries default' );
ok( $s['last_synced'] === 0 && $s['count'] === 0 && $s['last_error'] === '', 'store: empty meta defaults' );

// ── Normalize coerces types + fills missing keys ─────────────────────
$e = sn_discography_normalize_entry( array( 'title' => ' Hit ', 'artist' => 'X', 'year' => '2019', 'roles' => 'Producer' ) );
ok( $e['title'] === 'Hit' && $e['artist'] === 'X' && $e['year'] === 2019, 'normalize: trims + casts' );
ok( $e['roles'] === array( 'Producer' ), 'normalize: scalar role → array' );
ok( $e['id'] !== '', 'normalize: derives a stable id when none given' );

// ── Full release date preserved; year derived from it when absent ─────
$d = sn_discography_normalize_entry( array( 'title' => 'X', 'date' => '2019-04-18' ) );
ok( $d['date'] === '2019-04-18', 'normalize: keeps the full release date (YYYY-MM-DD)' );
ok( $d['year'] === 2019, 'normalize: derives year from date when year is absent' );
$d2 = sn_discography_normalize_entry( array( 'title' => 'X', 'year' => 2020, 'date' => '' ) );
ok( $d2['date'] === '' && $d2['year'] === 2020, 'normalize: year-only entry keeps empty date (honest reduced precision)' );

// ── Set sorts by year desc + recomputes meta ─────────────────────────
sn_discography_set( array(
	sn_discography_normalize_entry( array( 'title' => 'Old', 'artist' => 'A', 'year' => 2005 ) ),
	sn_discography_normalize_entry( array( 'title' => 'New', 'artist' => 'B', 'year' => 2024 ) ),
), 1700000000, '' );
$s = sn_discography_get();
ok( $s['entries'][0]['title'] === 'New' && $s['count'] === 2, 'set: sorts year desc + counts' );
ok( $s['last_synced'] === 1700000000, 'set: records sync ts' );

// ── XSS hardening: untrusted external title/artist are tag-sanitized ──
$hostile = sn_discography_normalize_entry( array( 'title' => '</script><b>boom', 'artist' => 'A <i>x</i>' ) );
ok( strpos( $hostile['title'], '<' ) === false && strpos( $hostile['title'], '>' ) === false, 'normalize: title tag-stripped (no </script> breakout)' );
ok( strpos( $hostile['artist'], '<' ) === false, 'normalize: artist tag-stripped' );

// ── v4.14.3: roles[] are tag-sanitized on write too (parity with title/artist).
// Muso credit-role strings are external/untrusted; a future unescaped consumer
// must not inherit a stored payload.
$hostile_roles = sn_discography_normalize_entry( array( 'title' => 'X', 'artist' => 'A', 'roles' => array( 'Producer', '<img src=x onerror=alert(1)>Mixer' ) ) );
ok( count( $hostile_roles['roles'] ) === 2, 'normalize: roles preserved as array' );
$joined_roles = implode( '|', $hostile_roles['roles'] );
ok( strpos( $joined_roles, '<' ) === false && strpos( $joined_roles, '>' ) === false, 'normalize: roles[] tag-stripped (parity with title/artist)' );
ok( in_array( 'Producer', $hostile_roles['roles'], true ), 'normalize: clean role text preserved' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
