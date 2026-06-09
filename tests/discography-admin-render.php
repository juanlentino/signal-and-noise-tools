<?php
/**
 * Render smoke test for inc/admin-forms/music.php (Monitoring → Music).
 *
 * Render-only modules silently rot: a typo'd sn_action value yields a dead
 * button the dispatcher never matches (the class of bug a v4.5.1 audit caught).
 * This drives the real renderer with a seeded store + stubbed WP layer and
 * asserts the OBSERVABLE contract: the two form actions match the dispatch-map
 * keys (music_save / music_sync), the secret is masked (never echoed raw), the
 * status reflects the store, and hostile stored data is escaped.
 *
 * @since plugin v4.13.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
function get_option( $n, $d = false ) {
	return array_key_exists( $n, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $n ] : $d;
}
function update_option( $n, $v, $a = null ) {
	$GLOBALS['__options'][ $n ] = $v;
	return true;
}
function current_user_can( $c ) {
	return true;
}
function human_time_diff( $from, $to = 0 ) {
	return '5 minutes';
}
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) {
	echo '<input type="hidden" name="_wpnonce" value="x">';
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_url( $s ) {
	return (string) $s;
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
		return trim( preg_replace( '/[^a-z0-9]+/', '-', $s ), '-' );
	}
}

require __DIR__ . '/../inc/settings.php';   // sn_mask_secret() — music.php's credential mask delegates here (v4.14.2).
require __DIR__ . '/../inc/discography-store.php';
require __DIR__ . '/../inc/muso-api.php';
require __DIR__ . '/../inc/spotify-api.php';
require __DIR__ . '/../inc/admin-forms/music.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "Music admin render smoke — plugin v4.13.0\n\n";

// Seed a populated, healthy store + configured Spotify creds.
sn_discography_set(
	array(
		sn_discography_normalize_entry( array( 'id' => 'al-1', 'title' => 'One', 'artist' => 'A', 'year' => 2024 ) ),
		sn_discography_normalize_entry( array( 'id' => 'al-2', 'title' => 'Two', 'artist' => 'B', 'year' => 2010 ) ),
	),
	time(),
	''
);
$GLOBALS['__options'][ SN_SPOTIFY_ID_OPT ]     = 'abc123clientid';
$GLOBALS['__options'][ SN_SPOTIFY_SECRET_OPT ] = 'topsecretvalue9999';

ob_start();
sn_admin_render_music_section();
$html = ob_get_clean();

// ── Form actions MUST equal the dispatch-map keys ────────────────────
ok( strpos( $html, 'name="sn_action" value="music_save"' ) !== false, 'emits the music_save action (matches dispatch map)' );
ok( strpos( $html, 'name="sn_action" value="music_sync"' ) !== false, 'emits the music_sync action (Sync now)' );
ok( strpos( $html, 'name="sn_action" value="music_synd"' ) === false, 'no mistyped action value' );

// ── Sub-tab round-trip hidden fields (flash lands back on Music) ─────
ok( substr_count( $html, 'name="sub" value="music"' ) >= 2, 'both forms carry sub=music for the PRG redirect' );

// ── Secret is MASKED, never echoed raw ───────────────────────────────
ok( strpos( $html, 'topsecretvalue9999' ) === false, 'raw client secret never rendered' );
ok( strpos( $html, '••••9999' ) !== false, 'secret shown masked (••••+last4)' );

// ── Status reflects the store (2 releases, healthy) ──────────────────
ok( strpos( $html, 'sn-pill--ok' ) !== false, 'healthy store → Synced pill' );
ok( strpos( $html, '2 release' ) !== false, 'status shows the cached release count' );

// ── Default Muso profile id surfaces, labelled credential-free ───────
ok( strpos( $html, '50d6a7c0-2d7b-4557-94b6-c472fa949df2' ) !== false, 'default Muso profile id shown' );
ok( strpos( $html, 'no credential' ) !== false, 'Muso source labelled credential-free' );

// ── Hostile stored last_error is escaped ─────────────────────────────
sn_discography_set( array( sn_discography_normalize_entry( array( 'id' => 'al-1', 'title' => 'One', 'year' => 2024 ) ) ), time(), '<script>alert(1)</script>' );
ob_start();
sn_admin_render_music_section();
$evil = ob_get_clean();
ok( strpos( $evil, '<script>alert(1)</script>' ) === false, 'hostile last_error escaped (no raw <script>)' );
ok( strpos( $evil, 'sn-pill--warn' ) !== false || strpos( $evil, 'sn-pill--err' ) !== false, 'error state surfaces a warn/err pill' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
