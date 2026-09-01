<?php
/**
 * Breached-credential rejection, Mode B (login-time, advisory, fail-OPEN, memoized) — v13.59.0.
 *
 * Properties, each the inverse of Mode A's: (1) the filter result is returned
 * UNTOUCHED on every path — breached, unavailable, disabled, wrong type;
 * (2) memoized against the stored hash: the second login with the same hash
 * makes NO request, a changed hash re-checks; (3) UNAVAILABLE stores nothing
 * and arms a site-wide backoff that suppresses the next lookup; (4) only a
 * plaintext that verifies against the account hash is ever checked (an
 * application password is skipped by construction); (5) the notice renders
 * the count for the breached user only; (6) the NEGATIVE CONTROL: "password"
 * over the captured 5BAA6 fixture is flagged.
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
$GLOBALS['__hooks'] = array();
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][] = array( $t, $c, $p, $a ); return true; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][] = array( $t, $c, $p, $a ); return true; }
class WP_User { public $ID; public $user_pass; public function __construct( $id, $pass ) { $this->ID = $id; $this->user_pass = $pass; } }
class WP_Error { public $code; public function __construct( $c = '' ) { $this->code = $c; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
// "Hashes": the stored value is 'H:' . plaintext, so verification is a string compare.
function wp_check_password( $password, $hash, $user_id = 0 ) { return 'H:' . $password === $hash; }
$GLOBALS['__meta'] = array();
function get_user_meta( $id, $k, $single = false ) { return $GLOBALS['__meta'][ $id ][ $k ] ?? ''; }
function update_user_meta( $id, $k, $v ) { $GLOBALS['__meta'][ $id ][ $k ] = $v; return true; }
$GLOBALS['__transients'] = array();
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = array( 'v' => $v, 'ttl' => $ttl ); return true; }
$GLOBALS['__remote'] = null; $GLOBALS['__requests'] = 0;
function wp_remote_get( $url, $args = array() ) { $GLOBALS['__requests']++; $GLOBALS['__remote_url'] = $url; $GLOBALS['__remote_args'] = $args; return $GLOBALS['__remote']; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
$GLOBALS['__current_user'] = 0;
function get_current_user_id() { return $GLOBALS['__current_user']; }
function get_edit_profile_url() { return 'https://example.test/wp-admin/profile.php'; }

require_once __DIR__ . '/../inc/breached-credentials.php';
require_once __DIR__ . '/../inc/breached-credentials-login.php';

echo "breached credentials — Mode B (login-time, advisory, memoized) — v13.59.0\n\n";

$fixture = __DIR__ . '/fixtures/hibp-range-5BAA6.txt';
ok( is_file( $fixture ), 'vacuity: the captured 5BAA6 fixture exists' );
$served = array( 'code' => 200, 'body' => (string) file_get_contents( $fixture ) );

// ─── pure pieces ───
$d = sn_hibp_login_digest( 'H:x' );
ok( 16 === strlen( $d ) && $d === sn_hibp_login_digest( 'H:x' ) && $d !== sn_hibp_login_digest( 'H:y' ), 'digest: 16 chars, deterministic, hash-specific' );
ok( true === sn_hibp_login_should_check( null, $d, false ), 'no memo → check' );
ok( false === sn_hibp_login_should_check( array( 'digest' => $d, 'verdict' => 'not_breached' ), $d, false ), 'memo for THIS hash → no check (any verdict)' );
ok( true === sn_hibp_login_should_check( array( 'digest' => 'other', 'verdict' => 'breached' ), $d, false ), 'memo for a PREVIOUS hash → re-check (self-invalidation on password change)' );
ok( false === sn_hibp_login_should_check( null, $d, true ), 'backoff active → no check even with no memo' );
ok( null === sn_hibp_login_memo_for( SN_HIBP_UNAVAILABLE, 0, $d, 1 ), 'UNAVAILABLE memoizes NOTHING' );
ok( 0 === sn_hibp_login_memo_for( SN_HIBP_NOT_BREACHED, 99, $d, 1 )['count'], 'a not_breached memo never carries a count' );
ok( 52 === sn_hibp_login_memo_for( SN_HIBP_BREACHED, 52, $d, 7 )['count'] && 7 === sn_hibp_login_memo_for( SN_HIBP_BREACHED, 52, $d, 7 )['checked_at'], 'a breached memo carries count + time' );

// ─── (6) NEGATIVE CONTROL + (1) never blocks + (2) memoized ───
$GLOBALS['__remote'] = $served;
$u = new WP_User( 5, 'H:password' );
$r = sn_hibp_on_authenticate( $u, 'juan', 'password' );
ok( $r === $u, 'NEGATIVE CONTROL half 1: a BREACHED password still logs in — the filter result is untouched' );
$memo = $GLOBALS['__meta'][5][ SN_HIBP_LOGIN_MEMO_META ] ?? null;
ok( is_array( $memo ) && 'breached' === $memo['verdict'] && 52372427 === $memo['count'] && sn_hibp_login_digest( 'H:password' ) === $memo['digest'], 'NEGATIVE CONTROL half 2: "password" over the real fixture is FLAGGED in the memo, keyed to the stored hash' );
ok( 1 === $GLOBALS['__requests'] && 2 === ( $GLOBALS['__remote_args']['timeout'] ?? 0 ), 'one request, with the login-path 2s timeout' );
ok( false === strpos( json_encode( $memo ), sha1( 'password' ) ) && ! in_array( 'password', $memo, true ), 'the memo holds neither the plaintext nor its full SHA-1' );
sn_hibp_on_authenticate( $u, 'juan', 'password' );
ok( 1 === $GLOBALS['__requests'], 'second login, same hash: NO request (memoized)' );
$u2 = new WP_User( 5, 'H:new-password-after-change' );
$GLOBALS['__remote'] = array( 'code' => 200, 'body' => "0000000000000000000000000000000000A:3\r\n" );
sn_hibp_on_authenticate( $u2, 'juan', 'new-password-after-change' );
ok( 2 === $GLOBALS['__requests'] && 'not_breached' === ( $GLOBALS['__meta'][5][ SN_HIBP_LOGIN_MEMO_META ]['verdict'] ?? '' ), 'password changed → digest differs → re-checked and memo replaced with not_breached' );

// ─── (3) UNAVAILABLE: nothing stored, backoff armed, next login suppressed ───
$GLOBALS['__meta'] = array(); $GLOBALS['__transients'] = array();
$GLOBALS['__remote'] = array( 'code' => 503, 'body' => '' );
$u3 = new WP_User( 6, 'H:whatever' );
$before = $GLOBALS['__requests'];
ok( $u3 === sn_hibp_on_authenticate( $u3, 'x', 'whatever' ), 'unavailable: login proceeds (fail-OPEN)' );
ok( ! isset( $GLOBALS['__meta'][6][ SN_HIBP_LOGIN_MEMO_META ] ), 'unavailable: NO memo written' );
ok( 900 === ( $GLOBALS['__transients'][ SN_HIBP_LOGIN_BACKOFF_KEY ]['ttl'] ?? 0 ), 'unavailable: 15-minute site-wide backoff armed' );
sn_hibp_on_authenticate( $u3, 'x', 'whatever' );
ok( $before + 1 === $GLOBALS['__requests'], 'during backoff: no further request' );

// ─── (4) only the ACCOUNT password is checked ───
$GLOBALS['__transients'] = array(); $GLOBALS['__meta'] = array();
$GLOBALS['__remote'] = $served;
$before = $GLOBALS['__requests'];
$u4 = new WP_User( 7, 'H:real-account-password' );
sn_hibp_on_authenticate( $u4, 'x', 'abcd efgh ijkl mnop' ); // an application password: does not verify against user_pass
ok( $before === $GLOBALS['__requests'] && empty( $GLOBALS['__meta'][7] ), 'a plaintext that does not verify against the account hash (an application password) is neither checked nor memoized' );
$we = new WP_Error( 'x' );
ok( $we === sn_hibp_on_authenticate( $we, 'x', 'password' ) && $before === $GLOBALS['__requests'], 'a failed login (WP_Error) passes through untouched, no request' );
ok( null === sn_hibp_on_authenticate( null, 'x', 'password' ) && $before === $GLOBALS['__requests'], 'null passes through, no request' );
ok( $u4 === sn_hibp_on_authenticate( $u4, 'x', '' ) && $before === $GLOBALS['__requests'], 'empty password: no request' );

// ─── (5) the notice ───
$GLOBALS['__meta'][8][ SN_HIBP_LOGIN_MEMO_META ] = array( 'digest' => 'd', 'verdict' => 'breached', 'count' => 1234, 'checked_at' => 1 );
$GLOBALS['__meta'][9][ SN_HIBP_LOGIN_MEMO_META ] = array( 'digest' => 'd', 'verdict' => 'not_breached', 'count' => 0, 'checked_at' => 1 );
$GLOBALS['__current_user'] = 8; ob_start(); sn_hibp_login_admin_notice(); $n8 = ob_get_clean();
$GLOBALS['__current_user'] = 9; ob_start(); sn_hibp_login_admin_notice(); $n9 = ob_get_clean();
$GLOBALS['__current_user'] = 10; ob_start(); sn_hibp_login_admin_notice(); $n10 = ob_get_clean();
ok( false !== strpos( $n8, '1,234' ) && false !== strpos( $n8, 'notice-warning' ) && false !== strpos( $n8, 'profile.php' ), 'breached user sees a warning with the count and a profile link' );
ok( '' === $n9 && '' === $n10, 'a not_breached user and a never-checked user see nothing' );
ok( '' === sn_hibp_login_notice_html( array( 'verdict' => 'unavailable' ), '' ), 'no notice for anything but breached' );

// ─── kill switch + hooks ───
define( 'SN_HIBP_LOGIN_DISABLED', true );
$before = $GLOBALS['__requests']; $GLOBALS['__meta'] = array();
$u5 = new WP_User( 11, 'H:password' );
ok( $u5 === sn_hibp_on_authenticate( $u5, 'x', 'password' ) && $before === $GLOBALS['__requests'] && empty( $GLOBALS['__meta'][11] ), 'SN_HIBP_LOGIN_DISABLED: no request, no memo, login untouched' );
$auth = array_values( array_filter( $GLOBALS['__hooks'], static fn( $h ) => 'authenticate' === $h[0] ) );
ok( 1 === count( $auth ) && 30 === $auth[0][2] && 3 === $auth[0][3] && 'sn_hibp_on_authenticate' === $auth[0][1], 'authenticate @30 (after core\'s 20) with 3 args' );
$tags = array_map( static fn( $h ) => $h[0], $GLOBALS['__hooks'] ); sort( $tags );
ok( array( 'admin_notices', 'authenticate' ) === $tags, 'exactly two hooks: authenticate + admin_notices — no wp_login (no password there), no set-time hook (Mode A)' );
$src = (string) file_get_contents( __DIR__ . '/../inc/breached-credentials-login.php' );
ok( false === strpos( $src, 'wp_die' ) && 0 === preg_match( '/return new WP_Error/', $src ), 'REGRESSION: the module has no way to fail a login (no WP_Error construction, no wp_die)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
