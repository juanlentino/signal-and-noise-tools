<?php
/**
 * Standalone test: exact page-view counts from the analytics worker 2.2.0
 * (inc/analytics-exact.php, inc/analytics-exact-reconcile.php).
 *
 * Run: php tests/analytics-exact.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$GLOBALS['opt'] = array(); $GLOBALS["qlog"] = array(); $GLOBALS['answers'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function sn_analytics_local_day( $now = null ) { return ( new DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' ); }
function sn_analytics_source( $day ) { return 'sn_pageviews_v2'; }
function sn_analytics_counted_class_select( array $h ) { return "if(blob7 = 'bot', 'bot', 'human')"; }
function sn_analytics_overcap_vdays() { return $GLOBALS['cap'] ?? array( 'hashes' => array(), 'ok' => true, 'truncated' => false ); }
function sn_analytics_query( $sql ) { $GLOBALS["qlog"][] = $sql; return array_shift( $GLOBALS['answers'] ); }
require __DIR__ . '/../inc/analytics-exact.php';

$A = 'aaaaaaaaaaaaaaaa'; $B = 'bbbbbbbbbbbbbbbb'; $C = 'cccccccccccccccc';

echo "\nValidation\n";
$good = array( 'v' => 1, 'started' => '2026-11-11', 'first_full_day' => '2026-11-12', 'today' => '2026-11-13', 'read_at' => 1794500000, 'truncated' => false,
	'days' => array( '2026-11-13' => array( array( $A, '/about', 3 ), array( $B, '/', 1 ) ) ) );
$v = sn_analytics_exact_validate( $good );
ok( is_array( $v ) && '2026-11-12' === $v['first_full_day'] && 2 === count( $v['days']['2026-11-13'] ), 'a well-formed block validates' );
foreach ( array(
	'no version'     => array_diff_key( $good, array( 'v' => 1 ) ),
	'bad day key'    => array( 'days' => array( 'today' => array() ) ) + $good,
	'bad hash'       => array( 'days' => array( '2026-11-13' => array( array( 'NOT-HEX', '/', 1 ) ) ) ) + $good,
	'path not /'     => array( 'days' => array( '2026-11-13' => array( array( $A, 'http://x', 1 ) ) ) ) + $good,
	'negative views' => array( 'days' => array( '2026-11-13' => array( array( $A, '/', -1 ) ) ) ) + $good,
	'string views'   => array( 'days' => array( '2026-11-13' => array( array( $A, '/', '3' ) ) ) ) + $good,
	'bad ffd'        => array( 'first_full_day' => 'soon' ) + $good,
) as $name => $bad ) {
	ok( null === sn_analytics_exact_validate( $bad ), "refused: $name" );
}
ok( null === sn_analytics_exact_validate( null ) && null === sn_analytics_exact_validate( array() ), 'an absent block ({} poke) holds nothing' );

echo "\nThe class read\n";
$sql = sn_analytics_exact_class_sql( '2026-11-13', array() );
ok( false !== strpos( $sql, "timestamp >= toDateTime('2026-11-13 05:00:00') AND timestamp < toDateTime('2026-11-14 05:00:00')" ), 'the site day in UTC (EST)' );
ok( false !== strpos( sn_analytics_exact_class_sql( '2026-11-01', array() ), "toDateTime('2026-11-01 04:00:00') AND timestamp < toDateTime('2026-11-02 05:00:00')" ), 'the 25-hour DST day is whole' );
ok( false !== strpos( $sql, "sum(if(blob1 = 'pv', 1, 0)) AS pv_stored" ) && false !== strpos( $sql, 'GROUP BY vid, class LIMIT 5001' ), 'kept page-view rows per visitor-day and class, every event classifying' );
ok( '' === sn_analytics_exact_class_sql( "x'; DROP", array() ), 'a bad day builds no statement' );

echo "\nReconcile\n";
$counts = array( array( $A, '/about', 3 ), array( $A, '/', 2 ), array( $B, '/', 1 ), array( $C, '/x', 4 ) );
$cls    = array( array( 'vid' => $A, 'class' => 'human', 'pv_stored' => 2 ), array( 'vid' => $B, 'class' => 'human', 'pv_stored' => 1 ), array( 'vid' => $C, 'class' => 'bot', 'pv_stored' => 0 ) );
$r = sn_analytics_exact_reconcile( $counts, $cls, true, true );
ok( true === $r['exact'] && 6 === $r['views']['human'] && 4 === $r['views']['bot'] && '' === $r['why'], 'exact: human 6, bot 4 (a visitor-day with every page view sampled away still classes by its other rows)' );
$r = sn_analytics_exact_reconcile( $counts, array_slice( $cls, 0, 2 ), true, true );
ok( false === $r['exact'] && 4 === $r['unclassed'] && false !== strpos( $r['why'], 'no class' ), 'a counted visitor-day missing from Analytics Engine: not exact' );
$r = sn_analytics_exact_reconcile( $counts, array_merge( $cls, array( array( 'vid' => $A, 'class' => 'suspect', 'pv_stored' => 0 ) ) ), true, true );
ok( false === $r['exact'] && false !== strpos( $r['why'], 'two classes' ), 'a visitor-day read as two classes: not exact' );
$r = sn_analytics_exact_reconcile( array( array( $A, '/', 1 ) ), array( array( 'vid' => $A, 'class' => 'human', 'pv_stored' => 2 ) ), true, true );
ok( false === $r['exact'] && false !== strpos( $r['why'], 'fewer counted' ), 'fewer counted than Analytics Engine kept: not exact (the counter lost writes)' );
ok( false === sn_analytics_exact_reconcile( $counts, $cls, false, true )['exact'], 'the day the counter started: not exact' );
ok( false === sn_analytics_exact_reconcile( $counts, $cls, true, false )['exact'], 'an over-cap list that did not read whole: not exact' );
ok( false === sn_analytics_exact_reconcile( $counts, null, true, true )['exact'], 'a failed class read: not exact' );
ok( false === sn_analytics_exact_reconcile( $counts, array_fill( 0, SN_ANALYTICS_EXACT_VID_MAX + 1, $cls[0] ), true, true )['exact'], 'a class read cut short: not exact' );

echo "\nIngest and the today figure\n";
$now   = time();
$today = sn_analytics_local_day( $now );
$good['first_full_day'] = ( new DateTimeImmutable( $today ) )->modify( '-1 day' )->format( 'Y-m-d' );
$block = sn_analytics_exact_validate( array( 'read_at' => $now, 'days' => array( $today => $counts ) ) + $good );
sn_analytics_exact_pending( $block );
$GLOBALS['answers'] = array( $cls );
sn_analytics_exact_ingest();
$stored = get_option( SN_ANALYTICS_EXACT_OPT );
ok( 6 === $stored['days'][ $today ]['views']['human'] && true === $stored['days'][ $today ]['exact'], 'stored: the day\'s totals per class' );
ok( false === strpos( serialize( $stored ), $A ), 'no visitor hash is stored' );
ok( array( 'views' => 6, 'read_at' => $now ) === sn_analytics_exact_today( $now ), 'today: the exact human figure' );
ok( null === sn_analytics_exact_today( $now + SN_ANALYTICS_EXACT_FRESH + 1 ), 'a stale reading is not shown' );
$GLOBALS['answers'] = array( array_slice( $cls, 0, 2 ) );
sn_analytics_exact_ingest();
ok( null === sn_analytics_exact_today( $now ) && false === get_option( SN_ANALYTICS_EXACT_OPT )['days'][ $today ]['exact'], 'a later reading that is not exact replaces it: no exact figure' );
sn_analytics_exact_pending( sn_analytics_exact_validate( array( 'read_at' => $now, 'truncated' => true, 'days' => array( $today => $counts ) ) + $good ) );
$GLOBALS['answers'] = array( $cls );
sn_analytics_exact_ingest();
ok( null === sn_analytics_exact_today( $now ) && false !== strpos( get_option( SN_ANALYTICS_EXACT_OPT )['days'][ $today ]['why'], 'cut short' ), 'a truncated push is never exact' );

echo "\nThe refresh route\n";
$rest = (string) file_get_contents( __DIR__ . '/../inc/analytics-refresh-rest.php' );
ok( false !== strpos( $rest, "sn_analytics_exact_validate( \$body['exact'] ?? null )" ) && false !== strpos( $rest, 'sn_analytics_exact_ingest();' ), 'the poke validates the block and ingests it after the response' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
