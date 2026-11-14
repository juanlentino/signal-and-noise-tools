<?php
/**
 * Reads (owner decision 2026-11-14): each branch of the definition, the
 * brief's four named views, the nightly store (measured with none vs not
 * measured), the read's SQL, the per-code count the Links view shows, and the
 * summary field both doors carry.
 * Run: php tests/analytics-reads.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
function add_action() {}
function add_filter() {}
function __( $s ) { return $s; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function sn_analytics_split_range_ok( $r ) { return '' === $r || 0 === strpos( (string) $r, ' AND timestamp ' ); }

require __DIR__ . '/../inc/analytics-reads.php';
require_once __DIR__ . '/../inc/link-codes.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Group: each branch of the definition\n";
ok( sn_analytics_is_read( 10000, 0, false ), '10 seconds of visible time is a read' );
ok( ! sn_analytics_is_read( 9999, 0, false ), '9.9 seconds alone is not' );
ok( sn_analytics_is_read( 0, 50, false ), 'a scroll to 50 percent is a read' );
ok( ! sn_analytics_is_read( 0, 25, false ), 'a scroll to 25 percent alone is not' );
ok( sn_analytics_is_read( 0, null, true ), 'a custom event (outbound, download) is a read' );
ok( ! sn_analytics_is_read( null, null, false ), 'a view with none of them is a view, not a read' );

echo "\nGroup: the brief's named views\n";
ok( sn_analytics_is_read( 5000, 0, true ), 'Asheville résumé download, 5 s plus a download: a read' );
ok( sn_analytics_is_read( 118000, 0, false ), 'Kansas City /r/rylo, 118 s: a read' );
ok( ! sn_analytics_is_read( 0, 0, false ), 'Cachoeiro de Itapemirim tag hit, 0 s: not a read' );
ok( ! sn_analytics_is_read( 0, null, false ), 'Morton Grove /resume, 0 s: a view, not a read' );

echo "\nGroup: the fold\n";
$row  = static fn( $vid, $path, array $o = array() ) => $o + array( 'day' => '2026-11-12', 'vid' => $vid, 'path' => $path, 'class' => 'human', 'time_ms' => 0, 'scrolled' => 0, 'custom' => 0, 'views' => 1 );
$rows = array(
	$row( 'a', '/r/rylo', array( 'time_ms' => 118000 ) ),
	$row( 'b', '/resume/', array( 'custom' => 1, 'time_ms' => 5000 ) ),
	$row( 'c', '/tag/c2pa/' ),
	$row( 'd', '/notes/x/', array( 'scrolled' => 1, 'class' => 'suspect' ) ),
	$row( 'e', '/notes/x/', array( 'time_ms' => 20000, 'day' => '2026-11-01' ) ), // outside the covered days
	$row( 'f', '/notes/y/', array( 'time_ms' => 60000, 'scrolled' => 1, 'views' => 0 ) ), // beacons with no page view
);
$f = sn_analytics_reads_fold( $rows, array( '2026-11-12', '2026-11-13' ) );
ok( 1 === $f['2026-11-12']['/r/rylo']['human'] && 1 === $f['2026-11-12']['/resume/']['human'], 'reads are counted per day, page and class' );
ok( ! isset( $f['2026-11-12']['/tag/c2pa/'] ), 'a page with no read is not stored' );
ok( 1 === $f['2026-11-12']['/notes/x/']['suspect'], 'the class is kept' );
ok( array() === $f['2026-11-13'], 'a covered day with no read is measured with none' );
ok( ! isset( $f['2026-11-01'] ), 'a row outside the covered days is dropped' );
ok( ! isset( $f['2026-11-12']['/notes/y/'] ), 'time and scroll with no page view behind them are not a read (a beacon-only visitor-day)' );

echo "\nGroup: the window, not measured vs 0\n";
$store = array( '2026-11-12' => $f['2026-11-12'], '2026-11-13' => array() );
$w     = sn_analytics_reads_window( '2026-11-10', '2026-11-13', 'human', null, $store );
ok( 2 === $w['reads'] && 2 === $w['measured_days'] && 4 === $w['days'] && true === $w['partial'], 'two reads over two measured days of four, partial' );
ok( null === $w['by_day']['2026-11-10'] && 0 === $w['by_day']['2026-11-13'], 'a day before the backfill reads not measured (null); a measured day with none reads 0' );
$old = sn_analytics_reads_window( '2026-08-01', '2026-08-03', 'human', null, $store );
ok( null === $old['reads'] && 0 === $old['measured_days'], 'a window with no measured day: reads null, never 0' );
$fig = sn_analytics_reads_figure( '2026-11-12', '2026-11-13', 'human', $store );
ok( 2 === $fig['reads'] && false === $fig['partial'] && SN_ANALYTICS_READS_DEFINITION === $fig['definition'] && ! isset( $fig['by_day'] ), 'the summary field carries the count and its definition' );
ok( array( 'reads', 'days', 'measured_days', 'partial', 'definition' ) === array_keys( sn_analytics_reads_schema()['properties'] ), 'the schema names every key the figure returns' );
ok( array_keys( sn_analytics_reads_schema()['properties'] ) === array_keys( $fig ), 'figure and schema agree key for key' );

echo "\nGroup: the per-code count the Links view shows\n";
$codes = sn_analytics_reads_by_code( '2026-11-01', '2026-11-30', array(
	'2026-11-11' => array( '/r/jon' => array( 'human' => 1 ), '/r/jon/' => array( 'human' => 1, 'bot' => 3 ) ),
	'2026-11-12' => $f['2026-11-12'],
) );
ok( array( 'jon' => 2, 'rylo' => 1 ) === $codes, 'human reads per code, /r/x and /r/x/ together, bots left out: ' . json_encode( $codes ) );

echo "\nGroup: the read\n";
$sql = sn_analytics_reads_sql( 'sn_pageviews_v2', '', '2026-11-12 05:00:00', '2026-11-13 05:00:00', 'America/New_York', "if(blob7 = 'bot', 'bot', 'human')" );
foreach ( array( "formatDateTime(timestamp, '%Y-%m-%d', 'America/New_York') AS day", "sumIf(double2, blob1 = 'tm') AS time_ms", "max(if(blob1 = 'sc' AND double1 >= 50, 1, 0)) AS scrolled", "max(if(blob1 = 'ce', 1, 0)) AS custom", 'GROUP BY day, vid, path, class', "toDateTime('2026-11-12 05:00:00')", "sumIf(_sample_interval, blob1 = 'pv') AS views", "blob1 IN ('pv','sc','tm','ce') AND blob7 != 'bot'" ) as $needle ) {
	ok( false !== strpos( $sql, $needle ), 'the read carries ' . $needle );
}
ok( false === strpos( $sql, ', double2, 0)' ) && false === strpos( $sql, ', double1, 0)' ), 'no if() mixing a Double with an Integer (the 11-11 422)' );
ok( '' === sn_analytics_reads_sql( 'x', '', '2026-11-12', '2026-11-13 05:00:00', '', 'c' ), 'a malformed bound builds no SQL' );
ok( false === strpos( sn_analytics_reads_sql( 'x', '', '2026-11-12 05:00:00', '2026-11-13 05:00:00', "x'; DROP", 'c' ), 'DROP' ), 'a zone that is not a zone name is not interpolated' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
