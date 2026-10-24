<?php
/**
 * Standalone test: application passwords reach the audit log (2026-10-24).
 *
 * An app password skips the login door, the edge guard and 2FA, and a refused
 * Basic-auth sign-in never fires wp_login_failed (core calls
 * wp_authenticate_application_password() directly from determine_current_user).
 * Core's own app-password hooks feed the log instead: successes are counted
 * (the MCP door authenticates every call), refusals and lifecycle changes are
 * rows. No attempted username, no password, no IP.
 *
 * Run: php tests/audit-log-app-passwords.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$GLOBALS['__options'] = array();
$GLOBALS['__writes']  = array();
$GLOBALS['__hooks']   = array();
$GLOBALS['__args']    = array();
$GLOBALS['__whoami']  = 0;
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
$GLOBALS['__refire'] = 0; // > 0: a foreign updated_option listener that re-enters the auth hook this many times
function update_option( $k, $v, $a = null ) {
	$GLOBALS['__options'][ $k ] = $v; $GLOBALS['__writes'][] = array( $k, $a );
	if ( $GLOBALS['__refire'] > 0 ) { $GLOBALS['__refire']--; fire( 'application_password_failed_authentication', new WP_Error( 'incorrect_password' ) ); }
	return true;
}
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); }
function sn_setting( $k, $d = null ) { return $d; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__hooks'][ $hook ] = $cb; $GLOBALS['__args'][ $hook ] = $args; }
function wp_next_scheduled() { return true; }
function do_action() {}
function __( $s, $d = null ) { return $s; }
class WP_Error {
	private $c;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->c = $c; }
	public function get_error_code() { return $this->c; }
}
$GLOBALS['__users'] = array(
	1 => (object) array( 'ID' => 1, 'user_login' => 'alice', 'roles' => array( 'administrator' ) ),
	2 => (object) array( 'ID' => 2, 'user_login' => 'bob', 'roles' => array( 'editor' ) ),
);
function get_userdata( $id ) { return $GLOBALS['__users'][ (int) $id ] ?? false; }
// Inside determine_current_user, wp_get_current_user() re-enters the filter
// and re-runs app-password auth: the auth hooks must never call it.
function wp_get_current_user() { $GLOBALS['__whoami']++; return $GLOBALS['__users'][1]; }
$_SERVER['REMOTE_ADDR']   = '203.0.113.9';
$_SERVER['PHP_AUTH_USER'] = 'victim-login';
$_SERVER['PHP_AUTH_PW']   = 'abcd EFGH ijkl MNOP qrst UVWX';

require dirname( __DIR__ ) . '/inc/audit-log.php';

function fire( $hook, ...$args ) { return isset( $GLOBALS['__hooks'][ $hook ] ) ? call_user_func_array( $GLOBALS['__hooks'][ $hook ], array_slice( $args, 0, $GLOBALS['__args'][ $hook ] ) ) : null; }
function rows() { return array_values( (array) ( snt_audit_get_blob()['changes'] ?? array() ) ); }
function last_row() { $r = rows(); return end( $r ) ?: array( 'kind' => '', 'subject' => '', 'detail' => '', 'actor' => null, 'high' => null ); }
function today_counts() { $c = snt_audit_get_counters_impl( 1 ); return $c[0] ?? array(); }

echo "\nGroup: core's five app-password hooks are registered\n";
foreach ( array( 'application_password_failed_authentication', 'application_password_did_authenticate', 'wp_create_application_password', 'wp_update_application_password', 'wp_delete_application_password' ) as $h ) {
	ok( isset( $GLOBALS['__hooks'][ $h ] ), "hooked: $h" );
}
ok( 2 === ( $GLOBALS['__args']['wp_create_application_password'] ?? 0 ), 'creation takes two arguments, so the new plaintext password is never even passed in' );

echo "\nGroup: successes are counted, never rows (the MCP door signs in on every call)\n";
$GLOBALS['__writes'] = array(); $GLOBALS['__whoami'] = 0;
for ( $i = 0; $i < 50; $i++ ) {
	fire( 'application_password_did_authenticate', $GLOBALS['__users'][1], array( 'uuid' => 'u-1', 'name' => 'Claude MCP' ) );
}
ok( array() === rows(), 'fifty successful sign-ins add no rows' );
ok( 50 === ( today_counts()['app_password_ok'] ?? null ), 'they are counted on the day: app_password_ok 50' );
ok( array() === array_filter( $GLOBALS['__writes'], static function ( $w ) { return SN_AUDIT_OPTION === $w[0]; } ), 'the hot path never rewrites the autoloaded audit blob' );
ok( array() !== $GLOBALS['__writes'] && array() === array_filter( $GLOBALS['__writes'], static function ( $w ) { return false !== $w[1]; } ), 'the count lives in its own option with autoload off' );
ok( 0 === $GLOBALS['__whoami'], 'the success hook never asks who the current user is' );

echo "\nGroup: refusals are rows, one per reason per day, with the full count beside them\n";
for ( $i = 0; $i < 30; $i++ ) {
	fire( 'application_password_failed_authentication', new WP_Error( 'incorrect_password', 'The provided password is an invalid application password.' ) );
}
for ( $i = 0; $i < 5; $i++ ) {
	fire( 'application_password_failed_authentication', new WP_Error( 'invalid_username', '<strong>Error:</strong> Unknown username.' ) );
}
$failed = array_values( array_filter( rows(), static function ( $r ) { return 'app_password_failed' === $r['kind']; } ) );
ok( 2 === count( $failed ), 'thirty-five refusals for two reasons make two rows, so a spray cannot push real changes out of the capped list' );
ok( 'incorrect_password' === ( $failed[0]['subject'] ?? '' ) && '' === ( $failed[0]['actor'] ?? 'x' ), 'the row names the reason code and no one signed in' );
ok( 35 === ( today_counts()['app_password_failed'] ?? null ), 'every refusal is counted: app_password_failed 35' );
ok( 0 === $GLOBALS['__whoami'], 'the refusal hook never asks who the current user is (it runs inside determine_current_user)' );

echo "\nGroup: a foreign listener that re-enters the auth hook does not recurse\n";
$before = (int) ( today_counts()['app_password_failed'] ?? 0 );
$GLOBALS['__refire'] = 50;
fire( 'application_password_failed_authentication', new WP_Error( 'incorrect_password' ) );
ok( $before + 1 === (int) ( today_counts()['app_password_failed'] ?? 0 ), 'one refusal is one count even when every write re-enters the hook (the nested pass is dropped)' );
$GLOBALS['__refire'] = 0;

echo "\nGroup: creating, renaming and revoking are rows on the account\n";
fire( 'wp_create_application_password', 1, array( 'uuid' => 'uuid-secret-1', 'name' => 'Claude MCP', 'password' => '$generic$hash' ), 'NEWPLAINTEXTPW0123456789', array() );
$c = last_row();
ok( 'app_password_created' === $c['kind'] && 'alice' === $c['subject'] && 'Claude MCP' === $c['detail'] && 'alice' === $c['actor'], 'created: the account, the password name, who did it' );
ok( true === $c['high'], 'an app password on an administrator account is marked high (it signs in past 2FA)' );
fire( 'wp_create_application_password', 2, array( 'uuid' => 'u-2', 'name' => 'Feed reader' ), 'X', array() );
ok( false === last_row()['high'], 'on an editor account it is not high' );
fire( 'wp_update_application_password', 1, array( 'uuid' => 'u-1', 'name' => 'Claude door' ), array( 'name' => 'Claude door' ) );
ok( 'app_password_updated' === last_row()['kind'] && 'Claude door' === last_row()['detail'], 'renamed: the new name' );
fire( 'wp_delete_application_password', 1, array( 'uuid' => 'u-1', 'name' => 'Claude door', 'last_ip' => '198.51.100.7' ) );
ok( 'app_password_revoked' === last_row()['kind'] && 'alice' === last_row()['subject'] && 'Claude door' === last_row()['detail'], 'revoked: the account and the name' );

echo "\nGroup: nothing secret or identifying is stored\n";
$all = json_encode( $GLOBALS['__options'] );
foreach ( array( 'victim-login', 'abcdEFGH', 'abcd EFGH', 'NEWPLAINTEXTPW', 'uuid-secret-1', '$generic$hash', '203.0.113.9', '198.51.100.7', 'Unknown username' ) as $never ) {
	ok( false === strpos( $all, $never ), "never stored: $never" );
}

echo "\nGroup: labels, retention\n";
foreach ( array( 'app_password_failed', 'app_password_created', 'app_password_updated', 'app_password_revoked' ) as $k ) {
	ok( $k !== snt_audit_change_label( $k ), "a label in words for $k" );
}
$days = get_option( 'sn_audit_app_passwords_v1', array() );
$days['2000-01-01'] = array( 'ok' => 9, 'failed' => 9, 'codes' => array() );
update_option( 'sn_audit_app_passwords_v1', $days, false );
snt_audit_prune_impl();
ok( ! isset( get_option( 'sn_audit_app_passwords_v1', array() )['2000-01-01'] ) && isset( get_option( 'sn_audit_app_passwords_v1', array() )[ gmdate( 'Y-m-d' ) ] ), 'prune drops day counts older than the retention window and keeps today' );

echo "\nGroup: the admin finding counts changes, not refused sign-ins (both twins share it)\n";
if ( ! function_exists( '_n' ) ) { function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }
require_once dirname( __DIR__ ) . '/inc/audit-log-admin-changes.php';
$mixed = array(
	array( 'kind' => 'app_password_failed', 'subject' => 'incorrect_password', 'high' => false, 'formatted' => '2026-10-24 09:00:00' ),
	array( 'kind' => 'app_password_failed', 'subject' => 'invalid_username', 'high' => false, 'formatted' => '2026-10-24 08:00:00' ),
	array( 'kind' => 'role_set', 'subject' => 'bob', 'high' => true, 'formatted' => '2026-10-23 12:00:00' ),
	array( 'kind' => 'plugin_activated', 'subject' => 'x/x.php', 'high' => false, 'formatted' => '2026-10-22 12:00:00' ),
);
$finding = snt_audit_log_changes_finding( $mixed );
ok( 0 === strpos( $finding, '2 changes in the window' ), 'two changes beside two refusal rows read as 2 changes: ' . $finding );
$finding = snt_audit_log_changes_finding( array( $mixed[0], $mixed[3] ) );
ok( false !== strpos( $finding, 'the newest at 2026-10-22 12:00:00' ), 'the newest change is a change, never a refused sign-in: ' . $finding );
$finding = snt_audit_log_changes_finding( array( $mixed[0], $mixed[1] ) );
ok( false === strpos( $finding, 'changes in the window' ) && false !== strpos( $finding, 'No account or site change' ), 'refusal rows alone are no changes: ' . $finding );
ok( false === snt_audit_is_change_row( $mixed[0] ) && true === snt_audit_is_change_row( $mixed[2] ), 'one predicate says which rows are changes (the digest uses it too)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
