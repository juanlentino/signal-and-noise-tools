<?php
/**
 * The read door's optional credential binding (2026-10-26 MCP doors audit, N0's
 * read half): the setter, the cross-door refusal in both setters, the handler
 * (ownership, Unbind as its own button, the write door's password refused by
 * name), and the state both screens paint.
 *
 * Run: php tests/mcp-read-binding.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
$GLOBALS['__can'] = true;
function current_user_can( $cap, $id = 0 ) { return 'manage_options' === $cap ? $GLOBALS['__can'] : true; }
function get_current_user_id() { return 7; }
$GLOBALS['__app_passwords'] = array();
class WP_Application_Passwords { public static function get_user_application_passwords( $u ) { return $GLOBALS['__app_passwords']; } }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }

require __DIR__ . '/../inc/mcp/mcp-rw-guard.php';
require __DIR__ . '/../inc/mcp/mcp-read-binding.php';
require __DIR__ . '/../inc/admin-post-actions.php';
require __DIR__ . '/../inc/admin-forms/mcp-connect-read-binding.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function reset_state() { $GLOBALS['__opts'] = array(); $GLOBALS['__app_passwords'] = array(); $GLOBALS['__can'] = true; }

$READ  = '11111111-1111-4111-8111-111111111111';
$WRITE = '22222222-2222-4222-8222-222222222222';
$NOT   = '33333333-3333-4333-8333-333333333333';
$mine  = static function () use ( $READ, $WRITE ) {
	return array( array( 'uuid' => $READ, 'name' => 'Claude read door', 'created' => 1700000000 ), array( 'uuid' => $WRITE, 'name' => 'Claude write door', 'created' => 1700000000 ) );
};

echo "Setters\n";
reset_state();
ok( '' === sn_mcp_read_bound_uuid(), 'unbound by default: the read door behaves as before' );
ok( false === sn_mcp_set_read_bound_uuid( 'garbage' ) && '' === sn_mcp_read_bound_uuid(), 'a malformed UUID is refused, option untouched' );
ok( sn_mcp_set_read_bound_uuid( strtoupper( $READ ) ) && $READ === sn_mcp_read_bound_uuid(), 'a well-formed UUID is stored lowercased' );
sn_mcp_set_rw_bound_uuid( $WRITE );
ok( false === sn_mcp_set_read_bound_uuid( $WRITE ) && $READ === sn_mcp_read_bound_uuid(), 'the read setter refuses the write door\'s password' );
ok( false === sn_mcp_set_rw_bound_uuid( $READ ) && $WRITE === sn_mcp_rw_bound_uuid(), 'and the write setter refuses the read door\'s' );
ok( sn_mcp_set_read_bound_uuid( '' ) && '' === sn_mcp_read_bound_uuid(), '\'\' clears the binding' );

echo "\nHandler\n";
reset_state();
$GLOBALS['__app_passwords'] = $mine();
$GLOBALS['__can'] = false;
ok( 'mcp_read_bind_invalid' === sn_handle_bind_mcp_read_credential( array( 'sn_mcp_read_uuid' => $READ ) ) && '' === sn_mcp_read_bound_uuid(), 'without manage_options nothing is bound' );
$GLOBALS['__can'] = true;
ok( 'mcp_read_bind_invalid' === sn_handle_bind_mcp_read_credential( array( 'sn_mcp_read_uuid' => $NOT ) ) && '' === sn_mcp_read_bound_uuid(), 'a UUID this user does not hold is refused' );
ok( 'mcp_read_bound' === sn_handle_bind_mcp_read_credential( array( 'sn_mcp_read_uuid' => $READ ) ) && $READ === sn_mcp_read_bound_uuid(), 'an owned password binds' );
ok( 'mcp_read_bind_choose' === sn_handle_bind_mcp_read_credential( array( 'sn_mcp_read_uuid' => '' ) ) && $READ === sn_mcp_read_bound_uuid(), 'an empty Save is not an unbind' );
ok( 'mcp_read_unbound' === sn_handle_bind_mcp_read_credential( array( 'sn_mcp_read_uuid' => '', 'sn_mcp_read_unbind' => '1' ) ) && '' === sn_mcp_read_bound_uuid(), 'Unbind, its own button, clears it' );
sn_mcp_set_rw_bound_uuid( $WRITE );
ok( 'mcp_read_bind_same_as_write' === sn_handle_bind_mcp_read_credential( array( 'sn_mcp_read_uuid' => $WRITE ) ) && '' === sn_mcp_read_bound_uuid(), 'the write door\'s password is refused by name' );
sn_mcp_set_rw_bound_uuid( '' );
sn_mcp_set_read_bound_uuid( $READ );
ok( 'mcp_rw_bind_same_as_read' === sn_handle_bind_mcp_rw_credential( array( 'sn_mcp_rw_uuid' => $READ ) ) && '' === sn_mcp_rw_bound_uuid(), 'and the write handler refuses the read door\'s by name' );
ok( 'mcp_rw_bound' === sn_handle_bind_mcp_rw_credential( array( 'sn_mcp_rw_uuid' => $WRITE ) ), 'the write handler still binds its own' );

echo "\nWhat the screens paint\n";
reset_state();
$GLOBALS['__app_passwords'] = $mine();
sn_mcp_set_rw_bound_uuid( $WRITE );
$s = sn_admin_mcp_read_binding_state();
ok( array( $READ ) === array_column( $s['passwords'], 'uuid' ), 'the write door\'s password is left out of the read list' );
ok( false !== strpos( sn_admin_mcp_read_binding_status( $s ), 'any administrator Application Password works on the read door, as before' ), 'unbound says nothing changed' );
sn_mcp_set_read_bound_uuid( $READ );
$s = sn_admin_mcp_read_binding_state();
ok( null !== $s['bound_password'] && false !== strpos( sn_admin_mcp_read_binding_status( $s ), 'Bound to Claude read door: it works only on /mcp' ), 'bound names the password and its limit' );
$GLOBALS['__app_passwords'] = array();
$s = sn_admin_mcp_read_binding_state();
ok( null === $s['bound_password'] && false !== strpos( sn_admin_mcp_read_binding_status( $s ), 'no longer matches any of your own' ), 'a revoked or foreign binding says so' );

$flash = (string) file_get_contents( __DIR__ . '/../inc/admin-flash-messages.php' );
$codes = array( 'mcp_read_bound', 'mcp_read_unbound', 'mcp_read_bind_invalid', 'mcp_read_bind_choose', 'mcp_read_bind_same_as_write', 'mcp_rw_bind_same_as_read' );
ok( array() === array_filter( $codes, static fn( $c ) => false === strpos( $flash, "'$c'" ) ), 'every flash code the handlers return has a message' );
$disp = (string) file_get_contents( __DIR__ . '/../inc/admin-post-handler.php' );
ok( 1 === preg_match( "/'bind_mcp_read_credential'\\s*=>\\s*'sn_handle_bind_mcp_read_credential'/", $disp ), 'the form\'s action reaches the handler' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
