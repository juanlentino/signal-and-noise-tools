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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
