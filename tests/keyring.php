<?php
/**
 * The keyring (15.2.0): the registry's shape, resolution order (constant >
 * site secret > saved > ''), the per-row switch to the site secret, the
 * other-half command, the filters that fill an empty handshake, and the save
 * handler's four verbs. Run: php tests/keyring.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['__opt'] = array(); $GLOBALS['__filters'] = array(); $GLOBALS['__settings'] = array();
function __( $s, $d = null ) { return $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $t ][] = $c; return true; }
function apply_filters( $t, $v ) { foreach ( $GLOBALS['__filters'][ $t ] ?? array() as $c ) { $v = $c( $v ); } return $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $a; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); return true; }
function sn_setting( $path, $d = null ) { return $GLOBALS['__settings'][ $path ] ?? $d; }
function sn_setting_update( $path, $v ) { $GLOBALS['__settings'][ $path ] = $v; }
function sn_setting_reset_cache() { $GLOBALS['__reset'] = ( $GLOBALS['__reset'] ?? 0 ) + 1; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function delete_transient( $k ) { $GLOBALS['__flushed'][] = $k; return true; }
function snt_mr_cache_flush() { $GLOBALS['__flushed'][] = 'mr'; }
function wp_unslash( $s ) { return $s; }

require dirname( __DIR__ ) . '/inc/keyring.php';
require dirname( __DIR__ ) . '/inc/admin-post-actions/keyring.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "keyring -- every credential in one place (15.2.0)\n";

// ── Shape
$rows = sn_keyring();
ok( 16 === count( $rows ) && isset( $rows['site_secret'], $rows['mr_read_token'], $rows['cf_token'], $rows['betterstack_token'], $rows['cloudways_api_key'] ), '16 rows, the site secret first among them' );
foreach ( $rows as $id => $row ) {
	ok( isset( $row['group'], $row['label'], $row['kind'], $row['about'], $row['feeds'] ) && in_array( $row['group'], array( 'site', 'cloudflare', 'issued' ), true ) && ( isset( $row['constant'] ) || isset( $row['option'] ) || isset( $row['setting'] ) ), "row $id carries group, label, kind, about, feeds and a home" );
}
$derived = array_keys( array_filter( $rows, static function ( $r ) { return 'site' === ( $r['derive'] ?? '' ); } ) );
ok( array( 'mr_read_token', 'srv_token', 'bridge_token', 'prov_hmac_secret' ) === $derived, 'exactly four handshakes can derive from the site secret; never the beacon token (public), never an issued token' );
foreach ( $derived as $id ) {
	ok( '' !== sn_keyring_other_half_command( $rows[ $id ] ) && false !== strpos( sn_keyring_other_half_command( $rows[ $id ] ), 'wrangler secret put ' . $rows[ $id ]['other_half']['secret'] ), "$id prints the wrangler command for its other half" );
}
ok( '' === sn_keyring_other_half_command( $rows['cf_token'] ), 'an issued token has no other half to print' );

// ── Resolution: '' → saved → site → constant
ok( '' === sn_keyring_source( 'mr_read_token' ) && '' === sn_credential( 'mr_read_token' ), 'unset: no source, empty value' );
$GLOBALS['__settings']['machine_readers.read_token'] = 'saved-mr';
ok( 'option' === sn_keyring_source( 'mr_read_token' ) && 'saved-mr' === sn_credential( 'mr_read_token' ), 'a saved value (in the settings blob for this row) reads as option' );
$GLOBALS['__opt'][ SN_SITE_SECRET_OPT ] = 'site-secret-1';
ok( 'option' === sn_keyring_source( 'mr_read_token' ), 'a site secret alone changes nothing: the row was not switched' );
$GLOBALS['__opt'][ SN_KEYRING_SITE_ROWS ] = array( 'mr_read_token' );
ok( 'site' === sn_keyring_source( 'mr_read_token' ) && 'site-secret-1' === sn_credential( 'mr_read_token' ), 'switched: the row derives from the site secret over its saved value' );
$GLOBALS['__opt'][ SN_SITE_SECRET_OPT ] = '';
ok( 'option' === sn_keyring_source( 'mr_read_token' ) && 'saved-mr' === sn_credential( 'mr_read_token' ), 'an empty site secret falls back to the saved value, never to empty' );
$GLOBALS['__opt'][ SN_SITE_SECRET_OPT ] = 'site-secret-1';
$GLOBALS['__opt'][ SN_KEYRING_SITE_ROWS ] = array( 'cf_token' );
ok( 'option' !== sn_keyring_source( 'cf_token' ) || 'site' !== sn_keyring_source( 'cf_token' ), 'a row that cannot derive ignores the switch' );
ok( '' === sn_keyring_source( 'cf_token' ), 'cf_token unset stays unset even when listed as switched' );
define( 'SN_MR_READ_TOKEN', 'const-mr' );
$GLOBALS['__opt'][ SN_KEYRING_SITE_ROWS ] = array( 'mr_read_token' );
ok( 'constant' === sn_keyring_source( 'mr_read_token' ) && 'const-mr' === sn_credential( 'mr_read_token' ), 'a constant wins over the site secret and the saved value' );
ok( '' === sn_credential( 'nope' ) && '' === sn_keyring_source( 'nope' ), 'an unknown id is empty, never an error' );

// ── The filters fill an empty handshake; a non-empty input passes untouched.
$GLOBALS['__opt'][ SN_KEYRING_SITE_ROWS ] = array( 'srv_token', 'bridge_token', 'prov_hmac_secret' );
ok( 'site-secret-1' === apply_filters( 'sn_server_token', '' ) && 'site-secret-1' === apply_filters( 'sn_analytics_refresh_secret', '' ) && 'site-secret-1' === apply_filters( 'sn_bridge_secret', '' ) && 'site-secret-1' === apply_filters( 'sn_prov_hmac_secret', '' ), 'the four handshake filters fill an empty value from the site secret when switched' );
ok( 'given' === apply_filters( 'sn_server_token', 'given' ) && 'given' === apply_filters( 'sn_mr_read_token', 'given' ), 'a value already present passes through the filter untouched' );
$GLOBALS['__opt'][ SN_KEYRING_SITE_ROWS ] = array();
ok( '' === apply_filters( 'sn_server_token', '' ), 'not switched, nothing saved: the filter leaves it empty (fail closed stays closed)' );

// ── The save handler: keep, clear, site, value; a constant never written.
$GLOBALS['__opt'] = array(); $GLOBALS['__settings'] = array(); $GLOBALS['__reset'] = 0;
ok( 'keyring_unchanged' === sn_handle_keyring_save( array( 'key_cf_token' => '', 'key_cf_zone' => '••••abcd' ) ) && array() === array_diff_key( $GLOBALS['__opt'], array( SN_KEYRING_SITE_ROWS => 1 ) ), 'empty and obscured fields change nothing' );
ok( 'keyring_saved' === sn_handle_keyring_save( array( 'key_cf_token' => 'tok-1', 'key_cf_zone' => 'zone-1', 'key_site_secret' => 'ss-1' ) ) && 'tok-1' === $GLOBALS['__opt']['sn_cf_api_token'] && false === $GLOBALS['__autoload']['sn_cf_api_token'] && 'zone-1' === $GLOBALS['__opt']['sn_cf_zone_id'] && 'ss-1' === $GLOBALS['__opt'][ SN_SITE_SECRET_OPT ], 'values save to their own options, never autoloaded' );
ok( 'keyring_saved' === sn_handle_keyring_save( array( 'key_srv_token' => 'site' ) ) && array( 'srv_token' ) === sn_keyring_site_rows() && 'ss-1' === sn_credential( 'srv_token' ), '"site" switches a derivable row; it now resolves to the site secret' );
ok( 'keyring_saved' === sn_handle_keyring_save( array( 'key_srv_token' => 'own-1' ) ) && array() === sn_keyring_site_rows() && 'own-1' === sn_credential( 'srv_token' ), 'a pasted value un-switches the row and is what it resolves to' );
ok( 'keyring_saved' === sn_handle_keyring_save( array( 'key_cf_token' => 'clear' ) ) && ! isset( $GLOBALS['__opt']['sn_cf_api_token'] ), '"clear" deletes the option' );
ok( 'keyring_saved' === sn_handle_keyring_save( array( 'key_cf_token' => 'site' ) ) && 'site' === $GLOBALS['__opt']['sn_cf_api_token'], '"site" on a row that cannot derive is saved literally: the leaf says which rows can' );
ok( 'keyring_unchanged' === sn_handle_keyring_save( array( 'key_mr_read_token' => 'pasted' ) ) && ! isset( $GLOBALS['__settings']['machine_readers.read_token'] ), 'a constant-locked row is never written' );
ok( 'keyring_saved' === sn_handle_keyring_save( array( 'key_srv_token' => 'x' ) ) && $GLOBALS['__reset'] > 0, 'the settings cache is reset after a save' );
// 15.2.1: a rotated key drops what it would serve stale.
$GLOBALS['__flushed'] = array();
sn_handle_keyring_save( array( 'key_betterstack_token' => 'bs-2', 'key_github_token' => 'gh-2', 'key_spotify_client_secret' => 'sp-2' ) );
ok( in_array( 'sn_uptime_status_snapshot', $GLOBALS['__flushed'], true ) && in_array( 'sn_uptime_availability', $GLOBALS['__flushed'], true ) && in_array( 'sn_spend_gh_usage', $GLOBALS['__flushed'], true ) && in_array( 'sn_spotify_token', $GLOBALS['__flushed'], true ), 'saving Better Stack, GitHub and Spotify rows drops their caches' );
$GLOBALS['__flushed'] = array();
sn_handle_keyring_save( array( 'key_bridge_token' => 'site' ) );
ok( array() === $GLOBALS['__flushed'], 'a row with nothing to flush flushes nothing' );
$GLOBALS['__flushed'] = array(); $GLOBALS['__opt'][ SN_KEYRING_SITE_ROWS ] = array();
sn_handle_keyring_save( array( 'key_cf_token' => 'x' ) ); // mr is constant-locked in this run; the switch path is pinned through srv_token
ok( array() === $GLOBALS['__flushed'], 'a row without flush entries flushes nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
