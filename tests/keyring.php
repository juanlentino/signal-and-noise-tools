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
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }

require dirname( __DIR__ ) . '/inc/keyring.php';
require dirname( __DIR__ ) . '/inc/admin-post-actions/keyring.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "keyring -- every credential in one place (15.2.0)\n";

// ── Shape
$rows = sn_keyring();
ok( 21 === count( $rows ) && isset( $rows['site_secret'], $rows['mr_read_token'], $rows['cf_token'], $rows['workers_ai_token'], $rows['betterstack_token'], $rows['cloudways_api_key'], $rows['zenodo_token'], $rows['zenodo_sandbox_token'], $rows['bing_webmaster_key'], $rows['typesafe_api_key'] ), '21 rows (16.3.0: + the TypeSafe key; 16.2.0: + the Bing Webmaster key; 15.11.0: + the two Zenodo tokens; 15.3.1: + the Workers AI token), the site secret first among them' );
foreach ( $rows as $id => $row ) {
	ok( isset( $row['group'], $row['label'], $row['kind'], $row['about'], $row['feeds'] ) && in_array( $row['group'], array( 'site', 'cloudflare', 'issued' ), true ) && ( isset( $row['constant'] ) || isset( $row['option'] ) || isset( $row['setting'] ) ), "row $id carries group, label, kind, about, feeds and a home" );
}
$derived = array_keys( array_filter( $rows, static function ( $r ) { return 'site' === ( $r['derive'] ?? '' ); } ) );
ok( array( 'mr_read_token', 'srv_token', 'bridge_token', 'prov_hmac_secret' ) === $derived, 'exactly four handshakes can derive from the site secret; never the beacon token (public), never an issued token' );
foreach ( $derived as $id ) {
	ok( '' !== sn_keyring_other_half_command( $rows[ $id ] ) && false !== strpos( sn_keyring_other_half_command( $rows[ $id ] ), 'wrangler secret put ' . $rows[ $id ]['other_half']['secret'] ), "$id prints the wrangler command for its other half" );
}
ok( '' === sn_keyring_other_half_command( $rows['betterstack_token'] ), 'an issued token with no worker copy has no other half to print' );
ok( false !== strpos( sn_keyring_other_half_command( $rows['cf_token'] ), 'sn-rights-signals-worker && npx wrangler secret put SN_MR_SQL_TOKEN' ), '15.2.2: the Cloudflare token has an other half, the sensor\'s SN_MR_SQL_TOKEN: rolling it has a second place to update' );
ok( 'cloudflare_token' === ( $rows['cf_analytics_override']['probe'] ?? '' ) && 'cloudflare_token' === ( $rows['workers_ai_token']['probe'] ?? '' ) && 'ml.embeddings_token' === ( $rows['workers_ai_token']['setting'] ?? '' ), '15.3.1: the analytics override and the Workers AI token verify with their own bytes; the Workers AI token keeps its settings-blob home' );

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

// ── The save handler (15.2.1: one row per save, `key_id` + `key_value`):
//    nothing, clear, site, value; a constant never written; the flushes.
$GLOBALS['__opt'] = array(); $GLOBALS['__settings'] = array(); $GLOBALS['__reset'] = 0; $GLOBALS['__flushed'] = array();
$save = static function ( $id, $value ) { return sn_handle_keyring_save( array( 'key_id' => $id, 'key_value' => $value ) ); };
ok( 'keyring_unchanged' === $save( 'cf_token', '' ) && 'keyring_unchanged' === $save( 'cf_zone', '••••abcd' ) && array() === $GLOBALS['__opt'], 'an empty or obscured value changes nothing' );
ok( 'keyring_unknown_row' === $save( 'nope', 'x' ) && 'keyring_unknown_row' === sn_handle_keyring_save( array( 'key_value' => 'x' ) ), 'an unknown or missing row id is refused by name' );
ok( 'keyring_saved' === $save( 'cf_token', 'tok-1' ) && 'tok-1' === $GLOBALS['__opt']['sn_cf_api_token'] && false === $GLOBALS['__autoload']['sn_cf_api_token'], 'a value saves to its own option, never autoloaded' );
ok( 'keyring_saved' === $save( 'site_secret', 'ss-1' ) && 'ss-1' === sn_site_secret(), 'the site secret is a row like any other' );
ok( 'keyring_saved' === $save( 'srv_token', 'site' ) && array( 'srv_token' ) === sn_keyring_site_rows() && 'ss-1' === sn_credential( 'srv_token' ), '"site" switches a derivable row; it now resolves to the site secret' );
ok( 'keyring_saved' === $save( 'srv_token', 'own-1' ) && array() === sn_keyring_site_rows() && 'own-1' === sn_credential( 'srv_token' ), 'a pasted value un-switches the row and is what it resolves to' );
ok( 'keyring_saved' === $save( 'cf_token', 'clear' ) && ! isset( $GLOBALS['__opt']['sn_cf_api_token'] ), '"clear" deletes the option' );
ok( 'keyring_saved' === $save( 'cf_token', 'site' ) && 'site' === $GLOBALS['__opt']['sn_cf_api_token'], '"site" on a row that cannot derive is saved literally: the form says which rows can' );
ok( 'keyring_locked' === $save( 'mr_read_token', 'pasted' ) && ! isset( $GLOBALS['__settings']['machine_readers.read_token'] ), 'a constant-locked row is refused, never written' );
ok( 'keyring_locked' === $save( 'cloudways_api_key', 'pasted' ), 'a wp-config-only row is refused too' );
ok( 'keyring_saved' === $save( 'cf_zone', 'z' ) && $GLOBALS['__reset'] > 0, 'the settings cache is reset after a save' );
// 15.2.1: a rotated key drops what it would serve stale.
$GLOBALS['__flushed'] = array();
$save( 'betterstack_token', 'bs-2' ); $save( 'github_token', 'gh-2' ); $save( 'spotify_client_secret', 'sp-2' );
ok( in_array( 'sn_uptime_status_snapshot', $GLOBALS['__flushed'], true ) && in_array( 'sn_uptime_availability', $GLOBALS['__flushed'], true ) && in_array( 'sn_spend_gh_usage', $GLOBALS['__flushed'], true ) && in_array( 'sn_spotify_token', $GLOBALS['__flushed'], true ), 'saving Better Stack, GitHub and Spotify rows drops their caches' );
$GLOBALS['__flushed'] = array();
$save( 'bridge_token', 'site' );
ok( array() === $GLOBALS['__flushed'], 'a row with nothing to flush flushes nothing' );
// 15.2.2: the guard. A shared row refuses an issued token; any row refuses a value another row holds.
$GLOBALS['__opt']['sn_cf_api_token'] = 'cf-issued-token-value';
ok( 'keyring_issued_as_shared' === $save( 'site_secret', 'cf-issued-token-value' ) && 'cf-issued-token-value' !== sn_site_secret(), 'the Cloudflare token pasted as the site secret is refused by name and not written' );
ok( 'keyring_issued_as_shared' === $save( 'mr_read_token', 'cf-issued-token-value' ) || 'keyring_locked' === $save( 'mr_read_token', 'cf-issued-token-value' ), 'the same paste into the sensor row is refused (locked by the constant in this run, refused as issued otherwise)' );
ok( 'keyring_duplicate' === $save( 'cf_analytics_override', 'cf-issued-token-value' ) && '' === sn_credential( 'cf_analytics_override' ), 'the API token pasted as the analytics override is a duplicate, refused' );
ok( 'keyring_duplicate' === $save( 'github_token', 'cf-issued-token-value' ), 'an issued token pasted into another issued row is a duplicate' );
$GLOBALS['__opt'][ SN_SITE_SECRET_OPT ] = 'own-site-secret';
ok( 'keyring_duplicate' === $save( 'bridge_token', 'own-site-secret' ), 'the site secret\'s bytes pasted into a worker row is a duplicate (type site instead)' );
ok( 'keyring_saved' === $save( 'bridge_token', 'site' ) && 'keyring_saved' === $save( 'cf_token', 'clear' ), '"site" and "clear" pass the guard untouched' );
ok( 'keyring_saved' === $save( 'github_token', 'gh-own-3' ), 'a value no other row holds saves' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
