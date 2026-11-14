<?php
/**
 * Time on page bucketed per view (2026-11-14): a view sent as several `tm`
 * deltas (the leave flush, a return to the tab, the "still here" ping) lands
 * in ONE band, by its summed time, never in a lower band per delta.
 * Run: php tests/analytics-buckets-time.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function sn_analytics_class_select() { return "if(blob7 = 'bot', 'bot', 'human')"; }
function sn_analytics_source() { return 'sn_pageviews_v2'; }
function sn_analytics_trailing_from( $d ) { return '2026-11-01'; }
function sn_analytics_excluded_path_sql() { return " AND blob2 NOT LIKE '/wp-admin%'"; }
function sn_analytics_overcap_where() { return ''; }
function sn_analytics_window_upper() { return ''; }
require __DIR__ . '/../inc/analytics-buckets-time.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$bands = array(
	array( 'label' => '0–10s', 'lo' => 0, 'hi' => 10000 ),
	array( 'label' => '10–30s', 'lo' => 10000, 'hi' => 30000 ),
	array( 'label' => '30–60s', 'lo' => 30000, 'hi' => 60000 ),
	array( 'label' => '1–3m', 'lo' => 60000, 'hi' => 180000 ),
	array( 'label' => '3m+', 'lo' => 180000, 'hi' => null ),
);
$sql = sn_analytics_buckets_time_view_sql( 7 );
foreach ( array( 'sum(double2) AS ms', 'max(_sample_interval) AS w', "WHERE blob1 = 'tm'", 'GROUP BY day, class, vid, path', 'LIMIT 5000' ) as $n ) {
	ok( false !== strpos( $sql, $n ), 'the read carries ' . $n );
}
ok( false === strpos( $sql, 'if(double2' ) && false === strpos( $sql, ', double2, 0)' ), 'no if() mixing a Double with an Integer' );

// A 25 s view sent as the 10 s ping and a 15 s leave flush: the read sums it to 25000.
$rows = array(
	array( 'day' => '2026-11-14', 'class' => 'human', 'vid' => 'a', 'path' => '/notes/x/', 'ms' => 25000.0, 'w' => 1 ),
	array( 'day' => '2026-11-14', 'class' => 'human', 'vid' => 'b', 'path' => '/notes/x/', 'ms' => 4000.0, 'w' => 1 ),
	array( 'day' => '2026-11-14', 'class' => 'human', 'vid' => 'c', 'path' => '/notes/y/', 'ms' => 200000.0, 'w' => 2 ),
	array( 'day' => '2026-11-14', 'class' => 'suspect', 'vid' => 'd', 'path' => '/', 'ms' => 12000.0, 'w' => 1 ),
);
$out = array();
foreach ( sn_analytics_buckets_time_melt( $rows, $bands ) as $r ) {
	$out[ $r['class'] . ' ' . $r['bucket'] ] = $r['views'];
}
ok( 1.0 === ( $out['human b1'] ?? null ), 'the 25 s view counts once, in 10–30s (per delta it would also sit in 0–10s)' );
ok( 1.0 === ( $out['human b0'] ?? null ), 'the 4 s view counts once, in 0–10s' );
ok( 2.0 === ( $out['human b4'] ?? null ), 'a view carries its sampling weight' );
ok( 1.0 === ( $out['suspect b1'] ?? null ), 'the class is kept' );
ok( 4 === count( $out ), 'one row per (class, band) that holds a view' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
