<?php
/**
 * The north star's quality layer (contract 14 arc, 2026-09-29): observe-only.
 * THE STAR-VALUE PIN: a bot-signals store that scores every human visitor-day
 * as likely automated leaves value, previous, series and trend exactly where
 * they were; only layers.quality moves.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['__opt'] = array();
function __( $s ) { return $s; }
function sn_setting( $k, $d = null ) { return $d; }
function add_action() {}
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function get_option( $k, $d = null ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function get_posts( $a ) { return array(); }
function sn_bot_signals_stored() { return $GLOBALS['__opt']['bot'] ?? null; }
// Two readers this week: one scrolled a note, one dwelled on another.
function sn_analytics_fetch_session_events( $from, $to, $scope ) {
	$t = time() - 3600;
	return array( 'configured' => true, 'capped' => false, 'visits' => array(
		array( array( 'vid' => 'a', 'ev' => 'pv', 'path' => '/notes/x/', 'ts' => $t ), array( 'vid' => 'a', 'ev' => 'sc', 'path' => '/notes/x/', 'ts' => $t, 'scroll' => 80 ) ),
		array( array( 'vid' => 'b', 'ev' => 'pv', 'path' => '/notes/y/', 'ts' => $t ), array( 'vid' => 'b', 'ev' => 'tm', 'path' => '/notes/y/', 'ts' => $t, 'dwell' => 60000 ) ),
	) );
}
require __DIR__ . '/../inc/north-star.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "North star: the quality layer is observe-only\n\n";

$bare = snt_nsm_reading( true );
ok( 2 === $bare['value'], 'fixture: two readers this week' );
ok( null === $bare['layers']['quality']['likely_automated_share']['value'], 'no store: the share is null, never zero' );
ok( true === $bare['layers']['quality']['likely_automated_share']['observe_only'], 'the layer says it is observe-only' );

$GLOBALS['__opt']['bot'] = array( 'window_days' => 14, 'human' => array( 'visitor_days' => 40, 'likely_automated' => 40 ) );
$scored = snt_nsm_reading( true );
foreach ( array( 'value', 'previous', 'prior_avg', 'series', 'trend' ) as $k ) {
	ok( $bare[ $k ] === $scored[ $k ], "THE STAR-VALUE PIN: $k is unchanged when every human visitor-day scores likely automated" );
}
ok( 100.0 === $scored['layers']['quality']['likely_automated_share']['value'] && '14d' === $scored['layers']['quality']['likely_automated_share']['window'], 'the share reads the store: 40 of 40 over 14d is 100.0' );
ok( $bare['layers']['intent'] === $scored['layers']['intent'], 'the intent layer is unchanged too' );

$GLOBALS['__opt']['bot'] = array( 'window_days' => 14, 'human' => array( 'visitor_days' => 8, 'likely_automated' => 1 ) );
ok( 12.5 === snt_nsm_quality( sn_bot_signals_stored() )['likely_automated_share']['value'], '1 of 8 is 12.5 percent' );
ok( null === snt_nsm_quality( array( 'human' => array( 'visitor_days' => 0, 'likely_automated' => 0 ) ) )['likely_automated_share']['value'], 'zero human visitor-days is not measured: null' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
