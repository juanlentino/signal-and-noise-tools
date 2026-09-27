<?php
/**
 * Tests for the ONE counted-human rule (inc/analytics-human-rule.php): the
 * condition helper, the over-cap list read, every routed builder's SQL, and
 * the engaged count riding the north star's read rule.
 * Run: php tests/analytics-human-rule.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
const SN_ANALYTICS_DATASET = 'sn_pageviews';

function add_action() {}
function add_filter() {}
function __( $s ) { return $s; }
function wp_next_scheduled() { return true; }
function home_url() { return 'https://example.com'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
$GLOBALS['__t'] = array();
$GLOBALS['__q'] = array();
$GLOBALS['__q_ret'] = array();
function get_transient( $k ) { return $GLOBALS['__t'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__t'][ $k ] = $v; return true; }
function sn_analytics_query( $sql ) { $GLOBALS['__q'][] = $sql; return $GLOBALS['__q_ret']; }
function sn_setting( $k, $d = null ) { return $GLOBALS['__ns'] ?? $d; }

require __DIR__ . '/../inc/analytics-derive.php';
require __DIR__ . '/../inc/analytics-rollup.php';
require __DIR__ . '/../inc/analytics-dims.php';
foreach ( array( 'sessions', 'drilldown', 'events-rollup', 'pageroles', 'percentiles', 'realtime', 'buckets', 'utm' ) as $m ) {
	require __DIR__ . "/../inc/analytics-{$m}.php";
}
require __DIR__ . '/../inc/north-star.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }

echo "\nGroup: condition helper (pure)\n";
ok( "blob7 = 'human'" === sn_analytics_counted_condition( 'human', array() ), 'empty list: exactly the class filter' );
ok( "blob7 = 'human' AND index1 NOT IN ('c70545e0be2a6240','abcdef01')" === sn_analytics_counted_condition( 'human', array( 'c70545e0be2a6240', 'ABCDEF01' ) ), 'hashes: the NOT IN clause (lower-cased)' );
ok( "blob7 = 'human'" === sn_analytics_counted_condition( 'human', array( "x' OR 1=1 --", 'zzzzzzzz', 'abc' ) ), 'non-hex / short hashes are refused' );
ok( false === strpos( sn_analytics_counted_condition( 'human', array( "c70545e0be2a6240'", 'c70545e0be2a6241' ) ), "c70545e0be2a6240'" ), 'a quote-bearing hash never reaches SQL' );
ok( "(blob7 = 'bot' OR index1 IN ('c70545e0be2a6240'))" === sn_analytics_counted_condition( 'bot', array( 'c70545e0be2a6240' ) ), 'bot: over-cap visitor-days read as automated' );
ok( "blob7 = 'human'" === sn_analytics_counted_condition( "human' --", array() ), 'unknown class reads as human' );
ok( '' === sn_analytics_overcap_and( array() ), 'group-by builders: no clause when empty' );

echo "\nGroup: over-cap list read\n";
ok( false !== strpos( sn_analytics_overcap_sql(), 'GROUP BY index1, toDate(timestamp) HAVING sum(_sample_interval) > 50' ), 'the verified HAVING shape at the cap' );
$GLOBALS['__q_ret'] = null;
$r = sn_analytics_overcap_vdays();
ok( false === $r['ok'] && array() === $r['hashes'], 'failed read: empty list flagged ok=false' );
ok( 1 === count( $GLOBALS['__q'] ), 'failed read: one query' );
sn_analytics_overcap_vdays();
ok( 1 === count( $GLOBALS['__q'] ), 'failed read negative-cached' );
$GLOBALS['__t'] = array(); $GLOBALS['__q'] = array();
$GLOBALS['__q_ret'] = array( array( 'vid' => 'c70545e0be2a6240', 'views' => 258 ), array( 'vid' => 'nope!', 'views' => 60 ) );
$r = sn_analytics_overcap_vdays();
ok( $r['ok'] && array( 'c70545e0be2a6240' ) === $r['hashes'], 'read: hex hashes kept, junk dropped' );

echo "\nGroup: every routed builder carries the exclusion\n";
$GLOBALS['__q_ret'] = array();
$not = "index1 NOT IN ('c70545e0be2a6240')";
$built = array(
	'sessions:sn_analytics_session_sql'                 => sn_analytics_session_sql( '2026-09-01', '2026-09-27', 'human', 100 ),
	'drilldown:sn_analytics_drilldown_sql'              => sn_analytics_drilldown_sql( 'country', array( 'US' ), '2026-09-01', '2026-09-27', 'human' ),
	'events-rollup:sn_analytics_events_rollup_sql'      => sn_analytics_events_rollup_sql( 7 ),
	'events-rollup:sn_analytics_event_props_rollup_sql' => sn_analytics_event_props_rollup_sql( 7 ),
	'pageroles:sn_analytics_pageroles_rollup_sql'       => sn_analytics_pageroles_rollup_sql( 7 ),
	'percentiles:sn_analytics_percentiles_sql'          => sn_analytics_percentiles_sql( 'sc', 'double1', '2026-09-01', '2026-09-27', 'human' ),
	'realtime:sn_analytics_realtime_sql'                => sn_analytics_realtime_sql(),
	'realtime:sn_analytics_views_today_sql'             => sn_analytics_views_today_sql( 100 ),
	'buckets:sn_analytics_buckets_hour_sql'             => sn_analytics_buckets_hour_sql( 7 ),
	'buckets:sn_analytics_buckets_dist_sql'             => sn_analytics_buckets_dist_sql( 'sc', 'double1', array( array( 'lo' => 0, 'hi' => null ) ), 7 ),
	'dims:sn_analytics_dims_rollup_sql'                 => sn_analytics_dims_rollup_sql( 'country', 7 ),
	'rollup:sn_analytics_rollup_sql'                    => sn_analytics_rollup_sql( 7 ),
	'rollup:sn_analytics_rollup_gated_sql'              => sn_analytics_rollup_gated_sql( 7 ),
	'utm:sn_analytics_utm_rollup_sql'                   => sn_analytics_utm_rollup_sql( 7 ),
);
foreach ( $built as $name => $sql ) {
	ok( false !== strpos( $sql, $not ), "$name excludes over-cap visitor-days" );
}
$GLOBALS['__q'] = array();
snt_nsm_research_links( '2026-09-01', '2026-09-27', time() );
ok( isset( $GLOBALS['__q'][0] ) && false !== strpos( $GLOBALS['__q'][0], "blob7 = 'human' AND {$not}" ), 'north-star-research:snt_nsm_research_links excludes over-cap visitor-days' );

echo "\nGroup: engaged uses the north star's read rule\n";
$ev = static function ( $vid, $ev, $path, $scroll = 0, $dwell = 0 ) {
	return array( 'vid' => $vid, 'ev' => $ev, 'path' => $path, 'scroll' => $scroll, 'dwell' => $dwell );
};
$visits = array(
	array( $ev( 'a', 'pv', '/about' ), $ev( 'a', 'sc', '/about', 60 ) ),          // scroll floor
	array( $ev( 'b', 'pv', '/x' ), $ev( 'b', 'tm', '/x', 0, 20000 ), $ev( 'b', 'tm', '/x', 0, 15000 ) ), // dwell slices sum
	array( $ev( 'c', 'pv', '/y' ), $ev( 'c', 'sc', '/y', 40 ) ),                  // below floor
	array( $ev( 'd', 'sc', '/z', 90 ) ),                                          // no pageview: not a read
);
ok( 2 === snt_nsm_engaged( $visits, snt_nsm_config() ), 'default floor: two engaged visitor-days' );
$GLOBALS['__ns'] = array( 'scroll' => 30, 'dwell_s' => 30 );
ok( 3 === snt_nsm_engaged( $visits, snt_nsm_config() ), 'follows the configured floor, not a second threshold' );

echo "\nResult: {$pass} passed, {$fail} failed.\n";
exit( $fail ? 1 : 0 );
