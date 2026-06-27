<?php
/**
 * Standalone test: Music sub-tab two-column shell contract (v6.42.0).
 *
 * The status hero + credential/featured forms stay in the main column; the
 * status details table + Sync-now action move to the rail.
 *
 * Run: php tests/music-shell.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SN_SPOTIFY_ID_OPT' ) ) { define( 'SN_SPOTIFY_ID_OPT', 'sn_spotify_id' ); }
if ( ! defined( 'SN_SPOTIFY_SECRET_OPT' ) ) { define( 'SN_SPOTIFY_SECRET_OPT', 'sn_spotify_secret' ); }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '1 hour'; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $n, $d = false ) { return $d; } }
if ( ! function_exists( 'sn_discography_get' ) ) { function sn_discography_get() { return $GLOBALS['__store'] ?? array( 'last_synced' => time() - 3600, 'count' => 5, 'last_error' => '' ); } }
if ( ! function_exists( 'sn_spotify_config' ) ) { function sn_spotify_config() { return false; } }
if ( ! function_exists( 'sn_muso_profile_id' ) ) { function sn_muso_profile_id() { return 'juan-lentino'; } }
if ( ! function_exists( 'sn_mask_secret' ) ) { function sn_mask_secret( $v ) { return '' === (string) $v ? '' : '****'; } }
if ( ! function_exists( 'sn_music_featured_get' ) ) { function sn_music_featured_get() { return array(); } }

require_once __DIR__ . '/../inc/admin-shell.php';
require_once __DIR__ . '/../inc/admin-forms/music.php';

function mus_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Test: Music renders main+rail shell (forms in main, sync detail in rail)\n";
ob_start();
sn_admin_render_music_section();
$html = ob_get_clean();
$rail_at = strpos( $html, '<aside class="sn-shell__rail"' );

mus_assert( false !== strpos( $html, '<div class="sn-shell">' ), 'wrapped in the two-column shell' );
mus_assert( false !== $rail_at, 'has a right rail' );

$creds_at  = strpos( $html, 'Spotify (optional)' );
$hero_at   = strpos( $html, 'sn-status-box-title">Synced' ); // pin to the hero title, not the pill
mus_assert( false !== $creds_at && $creds_at < $rail_at, 'credentials form sits in the main column' );
mus_assert( false !== $hero_at && $hero_at < $rail_at, 'status hero sits in the main column' );

$lastsync_at = strpos( $html, 'Last sync' );   // status details table row
$syncnow_at  = strpos( $html, 'Sync now' );
// is_int( $rail_at ) gates against a false (missing-rail) spurious pass.
mus_assert( is_int( $rail_at ) && false !== $lastsync_at && $lastsync_at > $rail_at, 'status details table sits in the rail' );
mus_assert( is_int( $rail_at ) && false !== $syncnow_at && $syncnow_at > $rail_at, 'Sync-now action sits in the rail' );

mus_assert( 1 === substr_count( $html, '</aside>' ), 'rail aside closes exactly once' );

// ─── Scenario B: failed sync — Failed hero in main, detail in rail ───
echo "\nScenario B: failed state (hero in main, status detail in rail)\n";
$GLOBALS['__store'] = array( 'last_synced' => 0, 'count' => 0, 'last_error' => 'api timeout' );
ob_start();
sn_admin_render_music_section();
$html_b    = ob_get_clean();
$rail_b    = strpos( $html_b, '<aside class="sn-shell__rail"' );
$failed_at = strpos( $html_b, 'Failed' );      // err pill text, in the main-column hero
$err_at    = strpos( $html_b, 'Last error' );  // status details row, in the rail
mus_assert( is_int( $rail_b ) && false !== $failed_at && $failed_at < $rail_b, 'Failed hero sits in the main column' );
mus_assert( is_int( $rail_b ) && false !== $err_at && $err_at > $rail_b, 'last-error row sits in the rail status table' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
