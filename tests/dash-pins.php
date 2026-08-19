<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

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

// The READ filter is what still earns its keep. v11.29.0 removed the setter and
// its REST route — nothing ever called them, so no pin could be set — but the
// reader still runs on every Dashboard render, and a stored preference can
// outlive the zone it names.
$GLOBALS['__meta'][7]['sn_dash_pins'] = 'not-an-array';
ok( sn_dash_pins( 7 ) === array(), 'corrupt stored meta reads as no pins rather than fataling' );

$GLOBALS['__meta'][8]['sn_dash_pins'] = array( 'fleet', 'a-zone-that-no-longer-exists' );
ok( sn_dash_pins( 8 ) === array( 'fleet' ), 'AN ID THAT IS NO LONGER A ZONE IS DROPPED on read' );

$GLOBALS['__meta'][9]['sn_dash_pins'] = array( 'measurement', 'attention' );
ok( sn_dash_pins( 9 ) === array( 'attention', 'measurement' ), 'the read normalises to allowlist order' );

$GLOBALS['__meta'][10]['sn_dash_pins'] = array();
ok( sn_dash_pins( 10 ) === array(), 'an empty stored list reads as empty' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
