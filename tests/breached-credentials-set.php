<?php
/**
 * Breached-credential rejection, Mode A (set-time, blocking, FAIL-CLOSED) — v13.58.0.
 *
 * Properties: (1) the pure decision lets ONLY an explicit not_breached through —
 * breached, unavailable, and an unrecognised verdict all refuse; (2) the guard
 * attaches the decision to core's errors object and counts it, never touching
 * the password; (3) an empty submission is "no change" and checks nothing;
 * (4) the kill switch is a constant; (5) both set-time hooks are registered
 * and nothing else is (registration_errors carries no password); (6) the
 * NEGATIVE CONTROL: a password KNOWN to be in the corpus, fed through the
 * real client over the captured 5BAA6 fixture, goes red — a breach check
 * that passes a breached password is worse than none.
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
function __( $s, $d = null ) { return $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
$GLOBALS['__hooks'] = array();
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][] = array( $t, $c, $a ); return true; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][] = array( $t, $c, $a ); return true; }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
class WP_Error {
	public $errors = array(); public $data = array();
	public function add( $code, $message, $data = '' ) { $this->errors[ $code ][] = $message; $this->data[ $code ] = $data; }
	public function get_error_codes() { return array_keys( $this->errors ); }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
// The client's network seam. Drive it with the captured fixture or a failure.
$GLOBALS['__remote'] = null;
function wp_remote_get( $url, $args = array() ) { $GLOBALS['__remote_url'] = $url; return $GLOBALS['__remote']; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }

require_once __DIR__ . '/../inc/breached-credentials.php';
require_once __DIR__ . '/../inc/breached-credentials-set.php';

echo "breached credentials — Mode A (set-time, fail-closed) — v13.58.0\n\n";

// ─── (1) the pure decision ───
ok( null === sn_hibp_set_time_decision( array( 'verdict' => SN_HIBP_NOT_BREACHED, 'count' => 0 ) ), 'not_breached → allow (null)' );
$b = sn_hibp_set_time_decision( array( 'verdict' => SN_HIBP_BREACHED, 'count' => 52372427 ) );
ok( 'sn_hibp_breached' === $b['code'] && false !== strpos( $b['message'], '52,372,427' ), 'breached → refused, message carries the COUNT' );
$u = sn_hibp_set_time_decision( array( 'verdict' => SN_HIBP_UNAVAILABLE, 'count' => 0 ) );
ok( 'sn_hibp_unavailable' === $u['code'] && false !== stripos( $u['message'], 'not set' ), 'FAIL-CLOSED: unavailable → refused, and the message says the password was NOT set' );
ok( 'sn_hibp_unavailable' === sn_hibp_set_time_decision( array( 'verdict' => 'maybe', 'count' => 0 ) )['code'], 'an unrecognised verdict refuses — the safe default for a value this code does not know' );
ok( 'sn_hibp_unavailable' === sn_hibp_set_time_decision( null )['code'], 'a non-array result refuses' );

// ─── (2) the guard attaches + counts, and never leaks the password ───
$GLOBALS['__remote'] = array( 'code' => 500, 'body' => '' );
$e = new WP_Error();
ok( true === sn_hibp_set_time_guard( $e, 'hunter2!' ) && array( 'sn_hibp_unavailable' ) === $e->get_error_codes(), 'guard over an HTTP 500: attaches sn_hibp_unavailable to the errors object' );
ok( 'pass1' === ( $e->data['sn_hibp_unavailable']['form-field'] ?? '' ), 'the error targets the pass1 field' );
ok( 1 === sn_hibp_set_stats()['unavailable_count'] && 0 === sn_hibp_set_stats()['breached_count'] && sn_hibp_set_stats()['last_unavailable_at'] > 0, 'stats: one unavailable rejection recorded' );
ok( false === strpos( wp_json_encode_stub( $GLOBALS['__opt'] ), 'hunter2' ) && false === strpos( wp_json_encode_stub( $e ), 'hunter2' ), 'neither the stats nor the error carries the password' );
function wp_json_encode_stub( $v ) { return (string) json_encode( $v ); }
ok( 'https://api.pwnedpasswords.com/range/' . strtoupper( substr( sha1( 'hunter2!' ), 0, 5 ) ) === $GLOBALS['__remote_url'], 'only the 5-char SHA-1 prefix left the origin' );

// ─── (3) an empty submission is "no change" ───
$GLOBALS['__remote_url'] = null;
$e2 = new WP_Error();
ok( false === sn_hibp_set_time_guard( $e2, '' ) && array() === $e2->get_error_codes() && null === $GLOBALS['__remote_url'], 'empty password: nothing checked, nothing attached, no request' );
$_POST = array();
ok( '' === sn_hibp_set_submitted_password(), 'no pass1 in the request → empty' );
$_POST['pass1'] = 'a\\\'b';
ok( "a'b" === sn_hibp_set_submitted_password(), 'pass1 is read UNSLASHED and unsanitised — the bytes WordPress will hash' );

// ─── (6) NEGATIVE CONTROL — the captured fixture for "password" ───
$fixture = __DIR__ . '/fixtures/hibp-range-5BAA6.txt';
ok( is_file( $fixture ), 'vacuity: the captured 5BAA6 fixture exists' );
$GLOBALS['__remote'] = array( 'code' => 200, 'body' => (string) file_get_contents( $fixture ) );
$e3 = new WP_Error();
ok( true === sn_hibp_set_time_guard( $e3, 'password' ) && array( 'sn_hibp_breached' ) === $e3->get_error_codes(), 'NEGATIVE CONTROL: "password" (52,372,427 breaches) is REFUSED through the real client + real fixture' );
ok( false !== strpos( $e3->errors['sn_hibp_breached'][0], '52,372,427' ), 'and the refusal quotes the corpus count' );
ok( 1 === sn_hibp_set_stats()['breached_count'], 'stats: one breached rejection recorded' );
$e4 = new WP_Error();
ok( false === sn_hibp_set_time_guard( $e4, 'correct-horse-battery-staple-9x!' ) && array() === $e4->get_error_codes(), 'a password whose suffix is absent from the served range passes (the stub serves 5BAA6 for every prefix; this one is not in it)' );

// ─── (4) the kill switch ───
define( 'SN_HIBP_SET_DISABLED', true );
$e5 = new WP_Error();
ok( true === sn_hibp_set_disabled() && false === sn_hibp_set_time_guard( $e5, 'password' ) && array() === $e5->get_error_codes(), 'SN_HIBP_SET_DISABLED: even "password" passes — the switch is a wp-config constant, not an option' );

// ─── (5) hooks: exactly the two set-time hooks ───
$tags = array_map( static fn( $h ) => $h[0], $GLOBALS['__hooks'] );
sort( $tags );
ok( array( 'user_profile_update_errors', 'validate_password_reset' ) === $tags, 'registers user_profile_update_errors + validate_password_reset and NOTHING else (no registration_errors: core registration has no password; no login hook: that is Mode B)' );
foreach ( $GLOBALS['__hooks'] as $h ) { ok( function_exists( $h[1] ), "hook callback {$h[1]} exists" ); }
$profile_args = array_values( array_filter( $GLOBALS['__hooks'], static fn( $h ) => 'user_profile_update_errors' === $h[0] ) )[0][2] ?? 0;
ok( 3 === $profile_args, 'user_profile_update_errors registered with 3 accepted args (errors, update, user)' );

// ─── the CLIENT stays hookless (its own pin lives in tests/breached-credentials.php; this is the sibling side) ───
$client = (string) file_get_contents( __DIR__ . '/../inc/breached-credentials.php' );
ok( false === strpos( $client, 'add_action' ) && false === strpos( $client, 'add_filter' ), 'the client file still registers no hooks — Mode A lives in its own file' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
