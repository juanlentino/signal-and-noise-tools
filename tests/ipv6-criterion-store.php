<?php
/**
 * The IPv6 criterion, stored daily so a WATCH can be cheap.
 *
 * The gauge computes from sn_analytics_query(), which does not cache — every
 * call is a live analytics-engine query. A watch is read by the morning brief
 * AND by sn-status{watches}, which is meant to answer "what is outstanding?"
 * cheaply. Wiring the live gauge in would make that read fire an outbound query
 * every time and change a cheap call's cost profile silently.
 *
 * @since 13.91.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

$GLOBALS['__opts']  = array();
$GLOBALS['__gauge'] = null;
$GLOBALS['__calls'] = 0;
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function wp_next_scheduled( $h, $args = array() ) { return false; }
function wp_schedule_event( $t, $r, $h ) { return true; }
/** The live gauge — counted, because the point is that a watch never calls it. */
function sn_ability_login_defense_ipv6_criterion() { $GLOBALS['__calls']++; return $GLOBALS['__gauge']; }

require __DIR__ . '/../inc/ipv6-criterion-store.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

echo "IPv6 criterion store (v13.91.0)\n\n";

// --- nothing stored is not "the criterion says no" ------------------------
ok( null === snt_ipv6_criterion_stored(), 'nothing stored reads as NULL, not as a decision' );

// --- a real reading is stored ---------------------------------------------
$GLOBALS['__gauge'] = array( 'decision' => 'withhold_unfinished_window', 'reason' => 'this window holds 10 and 196' );
ok( true === snt_ipv6_criterion_refresh(), 'a real reading is stored' );
$v = snt_ipv6_criterion_stored();
ok( 'withhold_unfinished_window' === $v['decision'] && false !== strpos( $v['reason'], '10 and 196' ),
	'with the decision AND the gauge\'s own reason — what a watch needs to say why it is quiet' );
ok( $v['measured_at'] > 0, 'and when it was taken' );

// --- an outage must not blank a value that was true -----------------------
// Same rule the purge report keeps: a broken sensor must not erase what was
// measured an hour ago.
$GLOBALS['__gauge'] = array( 'decision' => 'unknown', 'reason' => 'measurement unavailable (the analytics query failed)' );
ok( false === snt_ipv6_criterion_refresh(), 'an UNKNOWN reading is not stored' );
ok( 'withhold_unfinished_window' === snt_ipv6_criterion_stored()['decision'],
	'and the previous decision survives — an outage does not make a watch forget' );

$GLOBALS['__gauge'] = null;
ok( false === snt_ipv6_criterion_refresh(), 'a gauge returning nothing stores nothing' );
ok( null !== snt_ipv6_criterion_stored(), 'the record still stands' );

// --- the decision that matters --------------------------------------------
$GLOBALS['__gauge'] = array( 'decision' => 'build_ranges', 'reason' => '45.4% over 30 days, 22 days covered, 240 observations' );
snt_ipv6_criterion_refresh();
ok( 'build_ranges' === snt_ipv6_criterion_stored()['decision'], 'build_ranges is stored like any other real decision' );

// --- THE POINT: reading is free -------------------------------------------
// If a read ever calls the gauge, sn-status{watches} starts costing an
// analytics query per call, which is the surprise this store exists to avoid.
$before = $GLOBALS['__calls'];
snt_ipv6_criterion_stored();
snt_ipv6_criterion_stored();
snt_ipv6_criterion_stored();
ok( $before === $GLOBALS['__calls'],
	'READING COSTS NOTHING — three reads make zero gauge calls, so a watch stays cheap' );

// And the sanity half: refreshing DOES call it, so the counter is live.
snt_ipv6_criterion_refresh();
ok( $GLOBALS['__calls'] > $before, 'VACUITY GUARD: the counter does move when the gauge is actually called' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
