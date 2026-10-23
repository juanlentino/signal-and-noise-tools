<?php
/**
 * Guard: sn-metrics' analytics sections and analytics_query (20.4.0).
 *
 * The vocabulary refuses what the storage cannot answer; no floor: a one-visit
 * row is named and withheld is always zero (owner, 2026-10-23); the query
 * groups, filters, compares and orders over a fake $wpdb that serves fixture
 * rows by table, dim and window.
 *
 * Run: php tests/sn-metrics-analytics.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
const SN_ANALYTICS_DAILY_TABLE = 'sn_analytics_daily';
const SN_ANALYTICS_DIMS_TABLE  = 'sn_analytics_dims';
const SN_ANALYTICS_CLASSES     = array( 'human', 'suspect', 'bot' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

class WP_Error { public $code; public $data; function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->data = $d; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action() {}

// Window helpers: the real ones live in inc/analytics-admin.php; these keep the contract (site-local Y-m-d).
function snt_analytics_range_is_valid( $r ) { return 'all' === (string) $r || in_array( (int) $r, array( 7, 14, 30, 90, 365 ), true ); }
function snt_analytics_resolve_class( $c ) { return in_array( (string) $c, SN_ANALYTICS_CLASSES, true ) ? (string) $c : 'human'; }
function snt_analytics_resolve_window( $r ) { return 'all' === $r ? array( 'all', '2026-01-01', '2026-01-04' ) : array( (int) $r, '2026-01-03', '2026-01-04' ); }
function sn_analytics_prior_window( $f, $t ) { return array( '2026-01-01', '2026-01-02' ); }
function sn_analytics_canonical_path_sql( $c ) { return $c; }
function sn_analytics_self_hosts() { return array( 'juanlentino.com' ); }
function sn_analytics_canonical_source( $h, $self ) { return in_array( $h, $self, true ) || '' === $h ? '(direct)' : ( 'www.google.com' === $h ? 'Google' : $h ); }
function sn_analytics_source_category_of_label( $l ) { return array( 'Google' => 'search', '(direct)' => 'direct' )[ $l ] ?? 'other'; }

// Fixture rows: [table, dim, day, value, views, visits, scroll_w, time_w].
$GLOBALS['fx'] = array(
	array( 'daily', '', '2026-01-03', '/a', 40, 10, 800.0, 4000.0 ),
	array( 'daily', '', '2026-01-04', '/a', 20, 5, 200.0, 2000.0 ),
	array( 'daily', '', '2026-01-04', '/b', 4, 2, 40.0, 400.0 ),
	array( 'daily', '', '2026-01-01', '/a', 30, 8, 300.0, 3000.0 ),
	array( 'dims', 'country', '2026-01-03', 'US', 50, 9, 0, 0 ),
	array( 'dims', 'country', '2026-01-04', 'US', 10, 4, 0, 0 ),
	array( 'dims', 'country', '2026-01-04', 'IS', 1, 1, 0, 0 ),
	array( 'dims', 'country', '2026-01-01', 'US', 30, 6, 0, 0 ),
	array( 'dims', 'referrer', '2026-01-03', 'www.google.com', 12, 6, 0, 0 ),
	array( 'dims', 'referrer', '2026-01-03', 'juanlentino.com', 20, 9, 0, 0 ),
	array( 'dims', 'referrer', '2026-01-04', 'rare.example', 2, 1, 0, 0 ),
);
class Fake_WPDB {
	public $prefix = 'wp_'; public $last_error = '';
	function prepare( $sql, ...$a ) { return array( $sql, $a ); }
	function get_results( $q ) {
		list( $sql, $a ) = $q;
		$dims = false !== strpos( $sql, 'sn_analytics_dims' );
		$path = false !== strpos( $sql, 'day, path' );
		$out  = array();
		foreach ( $GLOBALS['fx'] as $r ) {
			if ( $r[0] !== ( $dims ? 'dims' : 'daily' ) || $r[2] < $a[0] || $r[2] > $a[1] || ( $dims && $r[1] !== $a[2] ) ) { continue; }
			$k = $r[2] . '|' . ( $dims || $path ? $r[3] : '' );
			$o = $out[ $k ] ?? array( 'day' => $r[2], 'value' => $dims || $path ? $r[3] : '', 'views' => 0, 'visits' => 0, 'scroll_w' => 0, 'time_w' => 0 );
			foreach ( array( 'views' => 4, 'visits' => 5, 'scroll_w' => 6, 'time_w' => 7 ) as $m => $i ) { $o[ $m ] += $r[ $i ]; }
			$out[ $k ] = $o;
		}
		return array_values( $out );
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require __DIR__ . '/../inc/sn-metrics-analytics-sections.php';
require __DIR__ . '/../inc/sn-metrics-analytics-fetch.php';
require __DIR__ . '/../inc/sn-metrics-analytics-vocab.php';
require __DIR__ . '/../inc/sn-metrics-analytics-query.php';

echo "The vocabulary\n";
$refused = static fn( $q ) => is_wp_error( snt_mq_validate( $q ) ) && 422 === snt_mq_validate( $q )->data['status'];
ok( $refused( array( 'dimensions' => array( 'city' ) ) ), 'an unknown dimension refuses (city is stored but left out on purpose)' );
ok( $refused( array( 'dimensions' => array( 'section' ) ) ), 'section refuses: no such dimension exists' );
ok( $refused( array( 'dimensions' => array( 'country', 'path' ) ) ), 'a pair without day refuses: the rollups hold one dimension per day' );
ok( $refused( array( 'dimensions' => array( 'day', 'path', 'country' ) ) ), 'three dimensions refuse' );
ok( $refused( array( 'dimensions' => array( 'country' ), 'metrics' => array( 'scroll_avg' ) ) ), 'scroll without path refuses' );
ok( $refused( array( 'dimensions' => array( 'path' ), 'filters' => array( 'country' => array( 'include' => array( 'US' ) ) ) ) ), 'a filter on an ungrouped dimension refuses' );
ok( $refused( array( 'dimensions' => array( 'path' ), 'sql' => 'SELECT 1' ) ), 'an unknown key refuses (no passthrough)' );
ok( $refused( array( 'dimensions' => array( 'path' ), 'limit' => 501 ) ), 'a limit past 500 refuses' );
$n = snt_mq_validate( array( 'dimensions' => array( 'day' ) ) );
ok( array( 'views', 'visits' ) === $n['metrics'] && 'day' === $n['order_by'] && 'asc' === $n['order'] && 25 === $n['limit'], 'defaults: views and visits, a day series reads oldest first, 25 rows' );

echo "\nNo floor\n";
$small = array( array( 'views' => 9, 'visits' => 2 ), array( 'views' => 50, 'visits' => 20 ), array( 'views' => 30, 'visits' => 3 ), array( 'views' => 1, 'visits' => 1 ) );
$f = snt_metrics_rank( $small, 1 );
ok( 1 === count( $f ) && 50 === $f[0]['views'], 'ranks by views, then slices to the limit' );
$p = snt_metrics_floored_payload( array( 'range' => 7 ), 'human', $small, 10 );
ok( array( 50, 30, 9, 1 ) === array_column( $p['rows'], 'views' ) && null === $p['floor'] && array( 'rows' => 0, 'views' => 0, 'visits' => 0 ) === $p['withheld'], 'one- and two-visit rows are listed; floor null, withheld zero' );

echo "\nThe query\n";
$q = snt_ability_analytics_query( array( 'range' => 7, 'dimensions' => array( 'day', 'country' ) ) );
ok( 3 === $q['rows_total'] && in_array( 'IS', array_column( $q['rows'], 'country' ), true ) && array( 'rows' => 0, 'views' => 0, 'visits' => 0 ) === $q['withheld'] && null === $q['floor'], 'day x country: Iceland\'s one visit is its own row' );
ok( 61 === array_sum( array_column( $q['rows'], 'views' ) ), 'the rows alone add up to the window' );
$q = snt_ability_analytics_query( array( 'range' => 7, 'dimensions' => array( 'path' ), 'metrics' => array( 'views', 'scroll_avg' ) ) );
ok( '/a' === $q['rows'][0]['path'] && 60 === $q['rows'][0]['views'] && 16.67 === $q['rows'][0]['scroll_avg'] && ! isset( $q['rows'][0]['visits'] ), 'path: days fold, scroll is views-weighted, only the asked metrics come back' );
$q = snt_ability_analytics_query( array( 'range' => 7, 'dimensions' => array( 'referrer_category' ) ) );
ok( 0 === $q['withheld']['rows'] && in_array( 'direct', array_column( $q['rows'], 'referrer_category' ), true ) && in_array( 'search', array_column( $q['rows'], 'referrer_category' ), true ), 'referrer_category: hosts fold to labels, labels to categories; the one-visit host is named' );
$q = snt_ability_analytics_query( array( 'range' => 7, 'dimensions' => array( 'source' ), 'filters' => array( 'source' => array( 'include' => array( 'Google' ) ) ) ) );
ok( array( 'Google' ) === array_column( $q['rows'], 'source' ), 'filters: include keeps only the named values' );
$q = snt_ability_analytics_query( array( 'range' => 7, 'dimensions' => array( 'country' ), 'compare' => 'previous' ) );
$us = $q['rows'][0];
ok( 'US' === $us['country'] && 30 === $us['previous']['views'] && 30 === $us['delta']['views'] && 0.9836 === $us['share'] && array( 'from' => '2026-01-01', 'to' => '2026-01-02' ) === $q['compare'], 'compare previous: previous values, deltas, share of the window\'s views, and the prior window named' );
$q = snt_ability_analytics_query( array( 'range' => 'all', 'dimensions' => array( 'day' ), 'compare' => 'previous' ) );
ok( isset( $q['compare']['reason'] ) && null === $q['floor'] && ! isset( $q['rows'][0]['delta'] ), 'range all has no previous window: it says so; floor is null' );
ok( is_wp_error( snt_ability_analytics_query( array( 'range' => 60, 'dimensions' => array( 'day' ) ) ) ), 'a range outside the vocabulary refuses' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
