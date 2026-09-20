<?php
/**
 * Breached-credential rejection, Mode A (set-time, blocking, FAIL-CLOSED) — v13.58.0.
 *
 * Properties: (1) the pure decision lets ONLY an explicit not_breached through —
 * breached, unavailable, and an unrecognised verdict all refuse; (2) the guard
 * attaches the decision to core's errors object and counts it, never touching
 * the password; (3) an empty submission is "no change" and checks nothing;
 * (4) the kill switch is a constant; (5) the three set-time hooks are
 * registered and nothing else is (registration_errors carries no password);
 * (6) the NEGATIVE CONTROL: a password KNOWN to be in the corpus, fed through
 * the real client over the captured 5BAA6 fixture, goes red — a breach check
 * that passes a breached password is worse than none; (7) the REST door
 * (17.2.2): a write on the users controller with a breached or uncheckable
 * password is refused at rest_dispatch_request with a 400 that names the
 * `password` param; a clean one, a read, another controller, a request with
 * no password and another filter's result all pass through untouched. The
 * seam is pinned by NAME: core's users controller never reads a WP_Error
 * back from rest_pre_insert_user (update_item casts the prepared object and
 * writes), so a refusal there is a silent 200 that drops the save.
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
	public function __construct( $code = '', $message = '', $data = '' ) { if ( '' !== $code ) { $this->add( $code, $message, $data ); } }
	public function add( $code, $message, $data = '' ) { $this->errors[ $code ][] = $message; $this->data[ $code ] = $data; }
	public function get_error_codes() { return array_keys( $this->errors ); }
	public function get_error_code() { return $this->get_error_codes()[0] ?? ''; }
	public function get_error_message( $code = '' ) { $code = '' === $code ? $this->get_error_code() : $code; return $this->errors[ $code ][0] ?? ''; }
	public function get_error_data( $code = '' ) { $code = '' === $code ? $this->get_error_code() : $code; return $this->data[ $code ] ?? null; }
}
// WP_REST_Request is ArrayAccess over its params plus get_method(); that is all the filter reads.
class WP_REST_Request implements ArrayAccess {
	private $p; private $m;
	public function __construct( $p = array(), $m = 'PUT' ) { $this->p = $p; $this->m = $m; }
	public function get_method() { return $this->m; }
	public function offsetExists( $k ): bool { return isset( $this->p[ $k ] ); }
	public function offsetGet( $k ): mixed { return $this->p[ $k ] ?? null; }
	public function offsetSet( $k, $v ): void { $this->p[ $k ] = $v; }
	public function offsetUnset( $k ): void { unset( $this->p[ $k ] ); }
}
class WP_REST_Users_Controller { public function update_item( $r ) { return 'wrote'; } }
class WP_REST_Posts_Controller { public function update_item( $r ) { return 'wrote'; } }
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

// ─── (7) the REST door: a write on the users controller ───
$users = new WP_REST_Users_Controller();
$users_h = array( 'callback' => array( $users, 'update_item' ) );
$req = static fn( $params, $m = 'PUT' ) => new WP_REST_Request( $params, $m );
$r = sn_hibp_on_rest_dispatch_request( null, $req( array( 'id' => 1, 'password' => 'password' ) ), '/wp/v2/users/(?P<id>[\\d]+)', $users_h );
ok( is_wp_error( $r ) && 'sn_hibp_breached' === $r->get_error_code(), 'REST: a breached password on PUT wp/v2/users/{id} is REFUSED as the dispatch result (core sends a non-null return as the response, before the controller runs)' );
ok( 400 === ( $r->get_error_data()['status'] ?? 0 ) && false !== strpos( (string) ( $r->get_error_data()['params']['password'] ?? '' ), '52,372,427' ), 'REST: 400, and params.password carries the refusal so the window lights the field' );
ok( 2 === sn_hibp_set_stats()['breached_count'], 'REST: the rejection is counted like any other' );
ok( is_wp_error( sn_hibp_on_rest_dispatch_request( null, $req( array( 'password' => 'password' ), 'POST' ), '/wp/v2/users', array( 'callback' => array( $users, 'create_item' ) ) ) ), 'REST: a new user with a breached password (POST create_item) is refused too' );
$GLOBALS['__remote'] = array( 'code' => 500, 'body' => '' );
$r2 = sn_hibp_on_rest_dispatch_request( null, $req( array( 'password' => 'hunter2!' ) ), '', $users_h );
ok( is_wp_error( $r2 ) && 'sn_hibp_unavailable' === $r2->get_error_code() && 400 === ( $r2->get_error_data()['status'] ?? 0 ), 'REST FAIL-CLOSED: the check unreachable → refused, never "not breached"' );
$GLOBALS['__remote'] = array( 'code' => 200, 'body' => (string) file_get_contents( $fixture ) );
ok( null === sn_hibp_on_rest_dispatch_request( null, $req( array( 'password' => 'correct-horse-battery-staple-9x!' ) ), '', $users_h ), 'REST: a clean password leaves the dispatch to core (null)' );
$GLOBALS['__remote_url'] = null;
ok( null === sn_hibp_on_rest_dispatch_request( null, $req( array( 'name' => 'Juan' ) ), '', $users_h ) && null === $GLOBALS['__remote_url'], 'REST: no password in the request → untouched, no request leaves' );
ok( null === sn_hibp_on_rest_dispatch_request( null, $req( array( 'password' => '' ) ), '', $users_h ) && null === $GLOBALS['__remote_url'], 'REST: an empty password is "no change" → untouched' );
ok( null === sn_hibp_on_rest_dispatch_request( null, $req( array( 'password' => 'password' ), 'GET' ), '', array( 'callback' => array( $users, 'get_items' ) ) ) && null === $GLOBALS['__remote_url'], 'REST: a READ carrying ?password= is not judged on it (GET never hashes)' );
ok( null === sn_hibp_on_rest_dispatch_request( null, $req( array( 'password' => 'password' ) ), '', array( 'callback' => array( new WP_REST_Posts_Controller(), 'update_item' ) ) ) && null === $GLOBALS['__remote_url'], 'REST: another controller\'s write is not this filter\'s business' );
$upstream = new WP_Error( 'rest_cannot_edit', 'Sorry.' );
ok( $upstream === sn_hibp_on_rest_dispatch_request( $upstream, $req( array( 'password' => 'password' ) ), '', $users_h ) && null === $GLOBALS['__remote_url'], 'REST: an earlier filter\'s result passes through unread, no request leaves' );
ok( null === sn_hibp_on_rest_dispatch_request( null, null, '', $users_h ) && null === sn_hibp_on_rest_dispatch_request( null, $req( array( 'password' => 'password' ) ), '', array() ), 'REST: no request object, or no handler → untouched' );
// The seam that looked right and is not: core's update_item() takes prepare_item_for_database()'s
// return without an is_wp_error() check, sets ->ID on it and writes (array) of it. A WP_Error
// there has no user_pass, so the breached password is not written but the client gets a 200 and
// every other field in the PUT is dropped. Pin the file OFF that hook by name.
$set_src = (string) file_get_contents( __DIR__ . '/../inc/breached-credentials-set.php' );
ok( false === strpos( $set_src, "'rest_pre_insert_user'" ), 'the file never hooks rest_pre_insert_user: core reads no refusal back from it (a WP_Error there is a silent 200 that drops the save)' );

// ─── (4) the kill switch ───
define( 'SN_HIBP_SET_DISABLED', true );
$e5 = new WP_Error();
ok( true === sn_hibp_set_disabled() && false === sn_hibp_set_time_guard( $e5, 'password' ) && array() === $e5->get_error_codes(), 'SN_HIBP_SET_DISABLED: even "password" passes — the switch is a wp-config constant, not an option' );

// ─── (5) hooks: exactly the two set-time hooks ───
$tags = array_map( static fn( $h ) => $h[0], $GLOBALS['__hooks'] );
sort( $tags );
ok( array( 'rest_dispatch_request', 'user_profile_update_errors', 'validate_password_reset' ) === $tags, 'registers user_profile_update_errors + validate_password_reset + rest_dispatch_request and NOTHING else (no registration_errors: core registration has no password; no login hook: that is Mode B; no rest_pre_insert_user: core reads no refusal back from it)' );
$rest_args = array_values( array_filter( $GLOBALS['__hooks'], static fn( $h ) => 'rest_dispatch_request' === $h[0] ) )[0][2] ?? 0;
ok( 4 === $rest_args, 'rest_dispatch_request registered with 4 accepted args (result, request, route, handler)' );
foreach ( $GLOBALS['__hooks'] as $h ) { ok( function_exists( $h[1] ), "hook callback {$h[1]} exists" ); }
$profile_args = array_values( array_filter( $GLOBALS['__hooks'], static fn( $h ) => 'user_profile_update_errors' === $h[0] ) )[0][2] ?? 0;
ok( 3 === $profile_args, 'user_profile_update_errors registered with 3 accepted args (errors, update, user)' );

// ─── the CLIENT stays hookless (its own pin lives in tests/breached-credentials.php; this is the sibling side) ───
$client = (string) file_get_contents( __DIR__ . '/../inc/breached-credentials.php' );
ok( false === strpos( $client, 'add_action' ) && false === strpos( $client, 'add_filter' ), 'the client file still registers no hooks — Mode A lives in its own file' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
