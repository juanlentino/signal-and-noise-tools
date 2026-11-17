<?php
/**
 * The since-the-fix sampling check (inc/analytics-sampling-since.php): reads
 * only rows written after the per-kind index fix, counts rows with a sample
 * interval above 1 per event type, keeps the counted-human class apart, and a
 * failed read is unknown (null), never 0.
 * Run: php tests/analytics-sampling-since.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/tmp/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; $c ? ++$pass : ++$fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; }
require __DIR__ . '/../inc/analytics-sampling-since.php';

$sql = sn_analytics_sampling_since_sql( 'CLASSEXPR' );
ok( false !== strpos( $sql, "timestamp >= toDateTime('2026-11-17 15:53:12')" ), 'reads only rows written after worker 2.3.0 went live' );
ok( false !== strpos( $sql, 'sum(if(_sample_interval > 1, 1, 0)) AS s' ) && false !== strpos( $sql, 'CLASSEXPR AS class' ), 'counts sampled rows per class' );
ok( false !== strpos( $sql, 'FROM sn_pageviews_v2' ) && false !== strpos( $sql, 'GROUP BY ev, class' ), 'per event type of the page-view dataset' );

$now = strtotime( '2026-11-20 15:53:12 UTC' );
$out = sn_analytics_sampling_since_fold( array(
	array( 'ev' => 'pv', 'class' => 'human', 'r' => 10, 's' => 0 ),
	array( 'ev' => 'pv', 'class' => 'bot', 'r' => 400, 's' => 300 ),
	array( 'ev' => 'sc', 'class' => 'human', 'r' => '6', 's' => '2' ),
	array( 'ev' => 'bad ev', 'class' => 'human', 'r' => 1, 's' => 1 ),
), $now );
ok( 72.0 === $out['hours'], 'hours since the fix' );
ok( array( 'rows' => 410, 'sampled' => 300, 'human_rows' => 10, 'human_sampled' => 0 ) === $out['events']['pv'], 'a sampled bot row is not a sampled human row' );
ok( 2 === $out['human_sampled'] && 302 === $out['sampled'], 'totals: human_sampled counts human rows only' );
ok( ! isset( $out['events']['bad ev'] ), 'a malformed event name is skipped' );

$none = sn_analytics_sampling_since_fold( null, $now );
ok( false === $none['read'] && null === $none['human_sampled'] && array() === $none['events'], 'a failed read is unknown, never 0' );
ok( 0 === sn_analytics_sampling_since_fold( array(), $now )['human_sampled'], 'a read with no rows is 0' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
