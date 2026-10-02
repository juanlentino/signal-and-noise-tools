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
$net = sn_analytics_network_human_sql();
$H   = "(blob7 != 'bot' AND ({$net}))";
ok( $H === sn_analytics_counted_condition( 'human', array() ), 'human: not a stored bot AND the read-time network rule' );
ok( "(blob7 != 'bot' AND NOT ({$net}))" === sn_analytics_counted_condition( 'suspect', array() ), 'suspect: not a stored bot AND NOT the read-time network rule' );
ok( "blob7 = 'bot'" === sn_analytics_counted_condition( 'bot', array() ), 'bot: the stored class (final, UA list only)' );
ok( false === strpos( sn_analytics_counted_condition( 'human', array() ), "blob7 = 'human'" ), 'human never trusts the stored human class' );
ok( 0 === strpos( $net, "(blob8 = 'Safari' AND (blob9 = 'iOS' OR blob9 = 'macOS') AND (blob12 ILIKE '%akamai%' OR blob12 ILIKE '%fastly%')) OR NOT (blob12 ILIKE '%amazon%'" ), 'network rule: relay Safari on iOS/macOS first, then NOT (DC or hosting)' );
ok( "blob12 ILIKE '%web2objects%')" === substr( $net, -29 ), 'network rule: the hosting list closes the NOT clause' );
ok( "{$H} AND index1 NOT IN ('c70545e0be2a6240','abcdef01')" === sn_analytics_counted_condition( 'human', array( 'c70545e0be2a6240', 'ABCDEF01' ) ), 'hashes: the NOT IN clause (lower-cased)' );
ok( $H === sn_analytics_counted_condition( 'human', array( "x' OR 1=1 --", 'zzzzzzzz', 'abc' ) ), 'non-hex / short hashes are refused' );
ok( false === strpos( sn_analytics_counted_condition( 'human', array( "c70545e0be2a6240'", 'c70545e0be2a6241' ) ), "c70545e0be2a6240'" ), 'a quote-bearing hash never reaches SQL' );
ok( "(blob7 = 'bot' OR index1 IN ('c70545e0be2a6240'))" === sn_analytics_counted_condition( 'bot', array( 'c70545e0be2a6240' ) ), 'bot: over-cap visitor-days read as automated' );
ok( $H === sn_analytics_counted_condition( "human' --", array() ), 'unknown class reads as human' );
ok( "if(blob7 = 'bot', 'bot', if({$net}, 'human', 'suspect'))" === sn_analytics_class_select(), 'class select: bot from storage, human/suspect from the network rule' );
ok( '' === sn_analytics_overcap_and( array() ), 'group-by builders: no clause when empty' );

echo "\nGroup: over-cap list read\n";
// AE refuses a function in GROUP BY ("you may only provide column names");
// HAVING and ORDER BY resolve against SELECT aliases. Every clause of the three
// must be bare identifiers, never a call.
$ae_clause_fns = static function ( $sql ) {
	$bad = array();
	if ( preg_match_all( '/\b(GROUP BY|HAVING|ORDER BY)\s+(.*?)(?=\bGROUP BY\b|\bHAVING\b|\bORDER BY\b|\bLIMIT\b|$)/s', (string) $sql, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $c ) {
			if ( false !== strpos( $c[2], '(' ) ) {
				$bad[] = $c[1] . ' ' . trim( $c[2] );
			}
		}
	}
	return $bad;
};
$oc = sn_analytics_overcap_sql();
// 19.6.1 pressure test: the worker rotates index1 at America/New_York midnight,
// so a visitor-day spans two UTC dates. Grouping by (vid, UTC date) split
// be3954b3adac9b3e's 65 views into 18 + 47 and it escaped the cap. The hash
// alone IS the visitor-day.
ok( false !== strpos( $oc, 'GROUP BY vid HAVING views > 50' ), 'groups by the visitor-day hash alone' );
ok( false === strpos( $oc, 'toDate(' ), 'never splits a visitor-day on the UTC date' );
ok( 1 === preg_match( '/GROUP BY ([^()]*?) HAVING/', $oc ), 'over-cap GROUP BY holds no function call' );
ok( array() === $ae_clause_fns( $oc ), 'over-cap GROUP BY / HAVING / ORDER BY are bare identifiers' );
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
	ok( array() === $ae_clause_fns( $sql ), "$name: no function in GROUP BY / HAVING / ORDER BY" );
}
$GLOBALS['__q'] = array();
snt_nsm_research_links( '2026-09-01', '2026-09-27', time() );
ok( isset( $GLOBALS['__q'][0] ) && false !== strpos( $GLOBALS['__q'][0], "{$H} AND {$not}" ), 'north-star-research:snt_nsm_research_links excludes over-cap visitor-days' );
ok( isset( $GLOBALS['__q'][0] ) && array() === $ae_clause_fns( $GLOBALS['__q'][0] ), 'north-star-research:snt_nsm_research_links: no function in GROUP BY / HAVING / ORDER BY' );

echo "\nGroup: builders that never see the path in PHP drop the excluded paths in AE (Unreleased)\n";
// The daily rollup and its gated twin carry the path and drop it in PHP
// (sn_analytics_is_excluded_path); every other pageview builder must say it in
// the WHERE, or its table counts the asset and admin beacons the daily one drops.
foreach ( array( 'dims:sn_analytics_dims_rollup_sql', 'utm:sn_analytics_utm_rollup_sql', 'buckets:sn_analytics_buckets_hour_sql', 'buckets:sn_analytics_buckets_dist_sql', 'pageroles:sn_analytics_pageroles_rollup_sql', 'realtime:sn_analytics_views_today_sql' ) as $name ) {
	ok( false !== strpos( $built[ $name ], sn_analytics_excluded_path_sql() ), "$name drops the excluded paths" );
}

echo "\nGroup: group-by builders select the read-time class and group by the alias\n";
foreach ( array( 'buckets:sn_analytics_buckets_hour_sql', 'buckets:sn_analytics_buckets_dist_sql', 'dims:sn_analytics_dims_rollup_sql', 'rollup:sn_analytics_rollup_sql', 'rollup:sn_analytics_rollup_gated_sql', 'utm:sn_analytics_utm_rollup_sql', 'realtime:sn_analytics_realtime_sql' ) as $name ) {
	$sql = $built[ $name ];
	ok( false !== strpos( $sql, sn_analytics_class_select() . ' AS class' ), "$name selects the read-time class AS class" );
	ok( false === strpos( $sql, 'blob7 AS class' ), "$name no longer selects the stored class" );
	ok( 1 === preg_match( '/GROUP BY [a-z_, ]*\bclass\b/', $sql ), "$name groups by the class alias" );
}

echo "\nGroup: statement budget (AE refuses over 10,000 characters)\n";
ok( 300 === SNT_ANALYTICS_VDAY_LIST_MAX, 'the over-cap list is capped at 300 (headroom under the 10,000-char statement cap)' );
$full = array();
for ( $i = 0; $i < SNT_ANALYTICS_VDAY_LIST_MAX; $i++ ) {
	$full[] = substr( hash( 'sha256', (string) $i ), 0, 16 );
}
$GLOBALS['__t'] = array( SNT_ANALYTICS_VDAY_CACHE_KEY => array( 'hashes' => $full, 'ok' => true, 'truncated' => true ) );
$longest = 0;
foreach ( array(
	sn_analytics_session_sql( '2026-09-01', '2026-09-27', 'suspect', 100 ),
	sn_analytics_drilldown_sql( 'country', array( 'US' ), '2026-09-01', '2026-09-27', 'suspect' ),
	sn_analytics_percentiles_sql( 'sc', 'double1', '2026-09-01', '2026-09-27', 'suspect' ),
	sn_analytics_events_rollup_sql( 7 ),
	sn_analytics_rollup_sql( 7 ),
	sn_analytics_buckets_dist_sql( 'sc', 'double1', array( array( 'lo' => 0, 'hi' => 25 ), array( 'lo' => 25, 'hi' => 50 ), array( 'lo' => 50, 'hi' => 75 ), array( 'lo' => 75, 'hi' => null ) ), 7 ),
) as $sql ) {
	$longest = max( $longest, strlen( $sql ) );
}
echo "  longest builder with a full list of 16-hex hashes: {$longest} chars\n";
ok( $longest <= SNT_ANALYTICS_SQL_MAX_CHARS - 1000 && ! sn_analytics_sql_too_long( str_repeat( 'x', $longest ) ), 'a full 300-hash list keeps every builder under the cap with 1,000 chars of headroom' );
$full[] = 'ffffffffffffffff';
for ( $i = 0; $i < 200; $i++ ) {
	$full[] = substr( hash( 'sha256', 'more' . $i ), 0, 16 );
}
$GLOBALS['__t'] = array( SNT_ANALYTICS_VDAY_CACHE_KEY => array( 'hashes' => $full, 'ok' => true, 'truncated' => true ) );
ok( sn_analytics_sql_too_long( sn_analytics_session_sql( '2026-09-01', '2026-09-27', 'suspect', 100 ) ), 'negative control: a longer list trips the guard (fails closed at sn_analytics_query)' );
$GLOBALS['__t'] = array();

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
