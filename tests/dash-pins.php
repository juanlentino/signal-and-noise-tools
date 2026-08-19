<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_DASH_PINS_TEST', true );
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }

$GLOBALS['__meta'] = array();
function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['__meta'][ $uid ][ $key ] ?? ( $single ? '' : array() ); }
// FAITHFUL to WordPress: update_metadata() returns FALSE when the stored value is
// already identical. A stub that always returns true hides every unchanged-write bug.
function update_user_meta( $uid, $key, $val ) {
	if ( ! empty( $GLOBALS['__meta_fail'] ) ) { return false; }
	$existing = $GLOBALS['__meta'][ $uid ][ $key ] ?? null;
	if ( null !== $existing && $existing === $val ) { return false; }
	$GLOBALS['__meta'][ $uid ][ $key ] = $val;
	return true;
}

require __DIR__ . '/../inc/dash-pins.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard pins\n\n";

ok( sn_dash_pins( 1 ) === array(), 'a user with no preference has no pins' );

sn_dash_set_pin( 1, 'fleet', true );
ok( sn_dash_pins( 1 ) === array( 'fleet' ), 'pinning stores the zone id' );
$again = sn_dash_set_pin( 1, 'fleet', true );
ok( sn_dash_pins( 1 ) === array( 'fleet' ), 'pinning twice does not duplicate' );
// WordPress reports an unchanged write as false. The CALLER asked for a state that
// now holds, so the answer is yes. Otherwise the REST route reports a failed pin
// for a pin that is, in fact, set.
ok( true === $again, 'RE-PINNING AN ALREADY-PINNED ZONE REPORTS SUCCESS, NOT A FAILED WRITE' );
sn_dash_set_pin( 1, 'measurement', true );
ok( count( sn_dash_pins( 1 ) ) === 2, 'a second pin is added' );
sn_dash_set_pin( 1, 'fleet', false );
ok( sn_dash_pins( 1 ) === array( 'measurement' ), 'unpinning removes only that zone' );
sn_dash_set_pin( 1, 'nope', false );
ok( sn_dash_pins( 1 ) === array( 'measurement' ), 'unpinning something unpinned is a no-op' );

// Pins are per user.
sn_dash_set_pin( 2, 'attention', true );
ok( sn_dash_pins( 1 ) === array( 'measurement' ), 'user 1 is unaffected by user 2' );
ok( sn_dash_pins( 2 ) === array( 'attention' ), 'user 2 has their own pin' );

// Only known zone ids are storable — the id becomes a CSS/data attribute.
$refused = sn_dash_set_pin( 3, 'evil"><script>', true );
ok( sn_dash_pins( 3 ) === array(), 'an unknown zone id is refused, not stored' );
// The read filters through the allowlist, so the assertion above passes even with
// the WRITE guard deleted — the hostile id just sits in user meta unread. Pin the
// write side directly: refused, and nothing persisted.
ok( false === $refused, 'refusing an unknown zone id is reported to the caller' );
ok( ! isset( $GLOBALS['__meta'][3][ SN_DASH_PIN_META ] ), 'A REFUSED ZONE ID IS NEVER PERSISTED' );

// Corrupt meta must not fatal.
$GLOBALS['__meta'][4]['sn_dash_pins'] = 'not-an-array';
ok( sn_dash_pins( 4 ) === array(), 'corrupt stored meta reads as no pins' );

// ── the REST toggle ─────────────────────────────────────────────────────────
class Fake_Req { private $p; public function __construct( $p ) { $this->p = $p; }
	public function get_param( $k ) { return $this->p[ $k ] ?? null; } }
class WP_REST_Response { public $data; public $status;
	public function __construct( $d, $s = 200 ) { $this->data = $d; $this->status = $s; } }
function get_current_user_id() { return 9; }

$r = sn_dash_pin_route_handler( new Fake_Req( array( 'zone' => 'fleet', 'pinned' => true ) ) );
ok( $r->status === 200, 'a valid toggle is 200' );
ok( sn_dash_pins( 9 ) === array( 'fleet' ), 'and it persisted' );
ok( $r->data['pins'] === array( 'fleet' ), 'the response echoes the new pin set' );

$r = sn_dash_pin_route_handler( new Fake_Req( array( 'zone' => 'bogus', 'pinned' => true ) ) );
ok( $r->status === 400, 'an unknown zone id is a 400' );
ok( sn_dash_pins( 9 ) === array( 'fleet' ), 'and nothing was written' );

// The route-level consequence of the unchanged-write fix in sn_dash_set_pin().
// Without it this is a false failure response for a zone that IS pinned.
$r = sn_dash_pin_route_handler( new Fake_Req( array( 'zone' => 'fleet', 'pinned' => true ) ) );
ok( $r->status === 200, 'RE-PINNING A PINNED ZONE THROUGH THE ROUTE IS 200, NOT AN ERROR' );
ok( $r->data['pins'] === array( 'fleet' ), 'and the pin set is unchanged' );

$r = sn_dash_pin_route_handler( new Fake_Req( array( 'zone' => 'fleet', 'pinned' => false ) ) );
ok( sn_dash_pins( 9 ) === array(), 'unpinning through the route works' );

// A write failure on a VALID zone is a 500, not a 400. Collapsing the two would
// send someone hunting a typo in a zone id that was spelled correctly.
$GLOBALS['__meta_fail'] = true;
$r = sn_dash_pin_route_handler( new Fake_Req( array( 'zone' => 'measurement', 'pinned' => true ) ) );
$GLOBALS['__meta_fail'] = false;
ok( $r->status === 500, 'A FAILED WRITE ON A VALID ZONE IS 500, NOT 400' );
ok( sn_dash_pins( 9 ) === array(), 'and nothing was persisted' );

// ── the gate ────────────────────────────────────────────────────────────────
// The permission_callback is the ONLY thing standing between this route and any
// logged-in subscriber. Nothing exercised it, so it is pinned by name here.
$GLOBALS['__route'] = array();
function register_rest_route( $ns, $path, $args ) { $GLOBALS['__route'] = array( $ns, $path, $args ); return true; }
if ( ! function_exists( '__return_true' ) ) { function __return_true() { return true; } }
$GLOBALS['__cap_asked'] = null;
function current_user_can( $cap ) { $GLOBALS['__cap_asked'] = $cap; return false; }
require_once __DIR__ . '/../inc/abilities-permission-helpers.php';

sn_dash_pin_register_route();
list( $ns, $path, $args ) = $GLOBALS['__route'];
ok( 'signal-noise/v1' === $ns && '/dash-pin' === $path, 'the route registers at signal-noise/v1/dash-pin' );
ok( 'POST' === $args['methods'], 'the toggle is POST, not GET' );
ok( ! empty( $args['permission_callback'] ), 'THE ROUTE HAS A PERMISSION CALLBACK AT ALL' );
ok( false === call_user_func( $args['permission_callback'] ), 'a user without the capability is refused' );
ok( 'manage_options' === $GLOBALS['__cap_asked'], 'and the gate asks for manage_options — the same cap that gates the admin page' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
