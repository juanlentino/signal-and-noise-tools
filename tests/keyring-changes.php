<?php
/**
 * The keyring change log (19.6.2): every write to a keyring option, by any
 * path, leaves row + set/clear + last 4 + source; never the value.
 * Run: php tests/keyring-changes.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['__opt'] = array(); $GLOBALS['__actions'] = array();
function __( $s, $d = null ) { return $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $t ][] = $c; return true; }
function do_action( $t, ...$args ) { foreach ( $GLOBALS['__actions'][ $t ] ?? array() as $c ) { $c( ...$args ); } }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__writes'][ $k ] = ( $GLOBALS['__writes'][ $k ] ?? 0 ) + 1; $had = array_key_exists( $k, $GLOBALS['__opt'] ); $old = $GLOBALS['__opt'][ $k ] ?? null; $GLOBALS['__opt'][ $k ] = $v; $had ? do_action( 'updated_option', $k, $old, $v ) : do_action( 'added_option', $k, $v ); return true; }
function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); do_action( 'deleted_option', $k ); return true; }
function get_current_user_id() { return 7; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function delete_transient( $k ) { return true; }

require dirname( __DIR__ ) . '/inc/keyring.php';
require dirname( __DIR__ ) . '/inc/keyring-changes.php';
require dirname( __DIR__ ) . '/inc/admin-post-actions/keyring.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "keyring-changes -- every keyring write leaves a line (19.6.2)\n";

$secret = 'abcdef0123456789srvtoken3d09';
sn_handle_keyring_save( array( 'key_id' => 'srv_token', 'key_value' => $secret ) );
$log = array_values( array_filter( sn_keyring_changes(), static function ( $e ) { return 'srv_token' === $e['row']; } ) );
ok( 1 === count( $log ) && 'set' === $log[0]['verb'] && '3d09' === $log[0]['last4'] && 'keyring_save' === $log[0]['source'] && 7 === $log[0]['user'], 'a form save logs row, set, last 4, source keyring_save, user' );
ok( false === strpos( serialize( sn_keyring_changes() ), 'abcdef' ), 'the value itself is never stored in the log' );

sn_handle_keyring_save( array( 'key_id' => 'srv_token', 'key_value' => 'clear' ) );
$last = sn_keyring_changes()[ count( sn_keyring_changes() ) - 1 ];
ok( 'srv_token' === $last['row'] && 'clear' === $last['verb'] && 'keyring_save' === $last['source'], 'a form clear logs clear' );

update_option( 'sn_srv_token', 'written-elsewhere-zz99' );
$last = sn_keyring_changes()[ count( sn_keyring_changes() ) - 1 ];
ok( 'set' === $last['verb'] && 'zz99' === $last['last4'] && 'keyring_save' !== $last['source'], 'a write that bypasses the form is logged too, with its own source' );

$n = count( sn_keyring_changes() ); $w = $GLOBALS['__writes'][ SN_KEYRING_CHANGES_OPT ];
update_option( 'blogname', 'x' ); update_option( 'cron', array( 1 ) ); delete_option( 'blogname' );
ok( $n === count( sn_keyring_changes() ) && $w === $GLOBALS['__writes'][ SN_KEYRING_CHANGES_OPT ], 'a non-keyring option write never touches the log option' );
$n = count( sn_keyring_changes() ); $w = $GLOBALS['__writes'][ SN_KEYRING_CHANGES_OPT ];
update_option( SN_KEYRING_CHANGES_OPT, sn_keyring_changes() + array( 99 => array( 'row' => 'x' ) ) );
ok( $n + 1 === count( sn_keyring_changes() ) && $w + 1 === $GLOBALS['__writes'][ SN_KEYRING_CHANGES_OPT ], 'writing the log does not log (one write, no recursion)' );

// Source switches write sn_keyring_site_rows, not the row option.
$GLOBALS['__opt']['sn_site_secret'] = 'site-secret-value-1';
sn_handle_keyring_save( array( 'key_id' => 'bridge_token', 'key_value' => 'site' ) );
$last = sn_keyring_changes()[ count( sn_keyring_changes() ) - 1 ];
ok( 'bridge_token' === $last['row'] && 'switch_site' === $last['verb'] && 'keyring_save' === $last['source'] && 7 === $last['user'], 'a switch to the site secret is logged as switch_site' );
sn_handle_keyring_save( array( 'key_id' => 'bridge_token', 'key_value' => 'own-bridge-value-77' ) );
$verbs = array_column( array_slice( sn_keyring_changes(), -2 ), 'verb' );
ok( in_array( 'switch_saved', $verbs, true ) && in_array( 'set', $verbs, true ), 'pasting a value logs switch_saved and the set' );

for ( $i = 0; $i < 60; $i++ ) { update_option( 'sn_srv_token', 'rotating-value-' . $i ); }
ok( SN_KEYRING_CHANGES_CAP === count( sn_keyring_changes() ), 'the log is capped' );

// Remote exposure: `changes` rides keyring-status, which must stay off the
// remote door (no twin), and so must sn-status{keyring}.
require dirname( __DIR__ ) . '/inc/mcp/mcp-remote-guard.php';
$verdicts = sn_mcp_remote_verdicts();
ok( isset( $verdicts['keyring'] ) && false === $verdicts['keyring']['remote'] && null === $verdicts['keyring']['twin'], 'sn-status{keyring} is local-only with no remote twin' );
ok( ! in_array( 'signal-noise/keyring-status', sn_mcp_remote_slugs(), true ) && 0 === count( preg_grep( '/keyring/', sn_mcp_remote_slugs() ) ), 'no remote slug serves the keyring, so `changes` never leaves the site' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
