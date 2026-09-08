<?php
/** Regression tests for audit capture at the Two-Factor authentication boundary. */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
$pass = 0; $fail = 0;
function check( $condition, $label ) {
	global $pass, $fail;
	if ( $condition ) { ++$pass; echo "PASS: $label\n"; }
	else { ++$fail; echo "FAIL: $label\n"; }
}
$GLOBALS['options'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['hooks'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][$key] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][$key] = $value; }
function wp_salt( $type ) { return 'fixture-salt'; }
function wp_date( $format, $time = null ) { return gmdate( $format, $time ?? time() ); }
function wp_unslash( $value ) { return $value; }
function sn_setting( $path, $default = null ) { return $default; }
function number_format_i18n( $number ) { return number_format( $number ); }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][$hook][] = array( $priority, $callback, $args ); }
function do_action( $hook, ...$args ) {
	$callbacks = $GLOBALS['hooks'][$hook] ?? array();
	usort( $callbacks, fn( $a, $b ) => $a[0] <=> $b[0] );
	foreach ( $callbacks as $entry ) { call_user_func_array( $entry[1], array_slice( $args, 0, $entry[2] ) ); }
}
class WP_User {
	public function __construct( public int $ID, public string $user_login ) {}
}
class WP_Error {
	public function __construct( private array $codes ) {}
	public function get_error_codes() { return $this->codes; }
}
require __DIR__ . '/../inc/audit-log.php';
require __DIR__ . '/../inc/security-digest.php';
$user = new WP_User( 7, 'owner' );
do_action( 'wp_login', 'owner', $user );
check( count( snt_audit_get_blob()['login_success'] ) === 1, 'normal login works without Two-Factor installed' );

// The provider predicate is the only plugin dependency. Events below follow
// upstream validate_login_form_2fa/process_provider; no authentication is mocked as successful by the SUT.
function install_two_factor_fixture() {
	class Two_Factor_Core {
		public static bool $enabled = true;
		public static function is_user_using_two_factor( $id ) { return self::$enabled && $id === 7; }
	}
}
install_two_factor_fixture();
Two_Factor_Core::$enabled = false;
do_action( 'wp_login', 'owner', $user );
check( count( snt_audit_get_blob()['login_success'] ) === 2, 'installed plugin with no enabled provider preserves normal logins' );
Two_Factor_Core::$enabled = true;
foreach ( array( 'WebAuthn', 'TOTP', 'Backup codes' ) as $provider ) {
	$before = count( snt_audit_get_blob()['login_success'] );
	do_action( 'wp_login', 'owner', $user );
	check( count( snt_audit_get_blob()['login_success'] ) === $before, "$provider: correct password is not a completed login" );
	do_action( 'two_factor_user_authenticated', $user, (object) array( 'label' => $provider ) );
	check( count( snt_audit_get_blob()['login_success'] ) === $before + 1, "$provider: verified completion creates exactly one record" );
}
$before = count( snt_audit_get_blob()['login_success'] );
do_action( 'wp_login', 'owner', $user );
do_action( 'two_factor_user_revalidated', $user, new stdClass() );
do_action( 'two_factor_webauthn_authentication_failed', $user, new Exception( 'private credential details' ) );
check( count( snt_audit_get_blob()['login_success'] ) === $before, 'abandoned MFA, revalidation and rejected assertions do not create login records' );
check( empty( snt_audit_get_blob()['counters'] ), 'provider failure hook does not duplicate the subsequent core failure event' );
do_action( 'wp_login', 'invalid', null );
do_action( 'two_factor_user_authenticated', null );
check( count( snt_audit_get_blob()['login_success'] ) === $before, 'invalid user callbacks are ignored' );
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
foreach ( array( array( 'incorrect_password' ), array( 'two_factor_invalid' ), array( 'two_factor_too_fast' ), array( 'two_factor_provider_missing' ), array( 'two_factor_invalid', 'two_factor_too_fast' ) ) as $codes ) {
	do_action( 'wp_login_failed', 'owner', new WP_Error( $codes ) );
}
do_action( 'wp_login_failed', 'legacy-caller' );
$row = snt_audit_get_counters_impl( 1 )[0];
check( $row['login_failed'] === 6, 'each core failure counts once, including callers without WP_Error' );
check( $row['mfa_failed'] === 2 && $row['mfa_throttled'] === 1 && $row['mfa_other'] === 1, 'error codes produce the distinct MFA subsets' );
check( $row['unique_ips_count'] === 1, 'MFA subsets do not duplicate unique-IP observations' );
$summary = snt_audit_get_summary_impl();
check( $summary['last_24h']['all_total'] === 6 && $summary['last_24h']['failed_total'] === 6, 'summary does not add subsets to failure totals' );
check( $summary['last_7d_vs_prior']['current'] === 6, 'trend does not double count MFA errors' );
$encoded = json_encode( snt_audit_get_blob() );
check( ! str_contains( $encoded, '192.0.2.1' ) && ! str_contains( $encoded, 'private credential details' ), 'raw IP and provider error details are not persisted' );
$digest = snt_security_digest_collect();
check( $digest['audit']['mfa_7d']['mfa_failed'] === 2 && $digest['audit']['failed_7d'] === 6, 'digest collects breakdown and unchanged total' );
check( str_contains( snt_security_digest_compose( $digest ), '2 rejected, 1 rate-limited, 1 other' ), 'digest renders MFA categories' );
$blob = snt_audit_get_blob();
$today = snt_audit_today_key();
$blob['counters'][$today] = array( 'login_failed' => 9 );
update_option( SN_AUDIT_OPTION, $blob );
$row = snt_audit_get_counters_impl( 1 )[0];
check( $row['login_failed'] === 9 && $row['mfa_failed'] === 0, 'historical failures remain unclassified without rewriting totals' );
do_action( 'wp_login_failed', 'owner', new WP_Error( array( 'two_factor_invalid' ) ) );
$row = snt_audit_get_counters_impl( 1 )[0];
check( $row['login_failed'] === 10 && $row['mfa_failed'] === 1, 'new MFA failure safely extends a legacy bucket' );
echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
