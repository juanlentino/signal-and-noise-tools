<?php
/**
 * Analytics 2.0 stitched reads (step 2): a read whose window crosses the clean
 * day reads the legacy dataset before the split and the second generation
 * from it, and merges exactly. Percentiles merge distributions; drilldown,
 * session events and bot signals merge rows.
 * Run: php tests/analytics-stitch.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
const SN_ANALYTICS_DATASET = 'sn_pageviews';
const SN_ANALYTICS_AE_ROW_CAP = 10000; // inc/analytics-api.php

function add_action() {}
function add_filter() {}
function __( $s ) { return $s; }
function apply_filters( $t, $v ) { return $v; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function get_option( $k, $d = false ) { return $d; }
$GLOBALS['__t'] = array();
function get_transient( $k ) {
	if ( SNT_ANALYTICS_VDAY_CACHE_KEY === $k ) { return array( 'hashes' => array(), 'ok' => true, 'truncated' => false ); }
	return $GLOBALS['__t'][ $k ] ?? false;
}
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__t'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__t'][ $k ] ); return true; }
$GLOBALS['__q'] = array(); $GLOBALS['__seq'] = array();
function sn_analytics_query( $sql ) { $GLOBALS['__q'][] = $sql; return array_shift( $GLOBALS['__seq'] ); }
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) { return array( array( 'value' => 'US' ) ); }

require __DIR__ . '/../inc/analytics-human-rule.php';
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_DIM_COLUMNS', array( 'country' => 'blob6' ) );
foreach ( array( 'percentiles', 'drilldown', 'sessions', 'bot-signals' ) as $m ) {
	require __DIR__ . "/../inc/analytics-{$m}.php";
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function reset_q( array $seq ) { $GLOBALS['__q'] = array(); $GLOBALS['__seq'] = $seq; $GLOBALS['__t'] = array(); }

sn_analytics_v2_clean_from( '2026-10-05' );
sn_analytics_v2_events_proven( true );
sn_analytics_clock( strtotime( '2026-10-20 12:00:00 UTC' ) );
$AT  = "toDateTime('2026-10-05 04:00:00')";
$OLD = " AND timestamp < {$AT}";
$NEW = " AND timestamp >= {$AT}";

echo "\nGroup: the shared runner\n";
reset_q( array( array( array( 'n' => 1 ) ) ) );
$sets = sn_analytics_stitched_rows( static fn( $s, $r ) => "SELECT 1 FROM {$s} WHERE x{$r}", '2026-10-06', '2026-10-19 23:59:59' );
ok( 1 === count( $sets ) && 'SELECT 1 FROM sn_pageviews_v2 WHERE x' === $GLOBALS['__q'][0], 'a window from the clean day: one read on the second generation, no bound' );
reset_q( array( array() ) );
sn_analytics_stitched_rows( static fn( $s, $r ) => "SELECT 1 FROM {$s} WHERE x{$r}", '2026-09-01', '2026-10-04 23:59:59' );
ok( 1 === count( $GLOBALS['__q'] ) && 'SELECT 1 FROM sn_pageviews WHERE x' === $GLOBALS['__q'][0], 'a window that ends before the split: legacy alone' );
reset_q( array( array( array( 'n' => 1 ) ), array( array( 'n' => 2 ) ) ) );
$sets = sn_analytics_stitched_rows( static fn( $s, $r ) => "SELECT 1 FROM {$s} WHERE x{$r}", '2026-09-01', '2026-10-19 23:59:59' );
ok( 2 === count( $sets ) && "SELECT 1 FROM sn_pageviews WHERE x{$OLD}" === $GLOBALS['__q'][0] && "SELECT 1 FROM sn_pageviews_v2 WHERE x{$NEW}" === $GLOBALS['__q'][1], 'a crossing window: legacy before the split, the second generation from it' );
reset_q( array( array(), null ) );
ok( null === sn_analytics_stitched_rows( static fn( $s, $r ) => "SELECT 1 FROM {$s}{$r}", '2026-09-01', '2026-10-19 23:59:59' ), 'a failed half fails the read' );
ok( '' === sn_analytics_read_key( '2026-09-01', '2026-10-04 23:59:59' ) && '|sn_pageviews_v2' === sn_analytics_read_key( '2026-10-06', '2026-10-19 23:59:59' ) && '|stitch@2026-10-05 04:00:00' === sn_analytics_read_key( '2026-09-01', '2026-10-19 23:59:59' ), 'the cache key names what answers the window' );
ok( sn_analytics_split_range_ok( $OLD ) && sn_analytics_split_range_ok( $NEW ) && ! sn_analytics_split_range_ok( " AND 1=1" ) && ! sn_analytics_split_range_ok( "{$OLD} OR 1=1" ), 'only an exact split bound passes' );

echo "\nGroup: drilldown\n";
reset_q( array(
	array( array( 'path' => '/a/', 'views' => 10, 'visits' => 4 ), array( 'path' => '/b/', 'views' => 3, 'visits' => 2 ) ),
	array( array( 'path' => '/a/', 'views' => 5, 'visits' => 3 ), array( 'path' => '/c/', 'views' => 20, 'visits' => 9 ) ),
) );
$dd = sn_analytics_drilldown( 'country', 'US', '2026-09-20', '2026-10-19', 'human' );
ok( 2 === count( $GLOBALS['__q'] ) && false !== strpos( $GLOBALS['__q'][0], 'FROM sn_pageviews WHERE' ) && false !== strpos( $GLOBALS['__q'][0], $OLD ) && false !== strpos( $GLOBALS['__q'][1], 'FROM sn_pageviews_v2 WHERE' ) && false !== strpos( $GLOBALS['__q'][1], $NEW ), 'a crossing drilldown reads each generation for its side' );
ok( array( array( 'path' => '/c/', 'views' => 20, 'visits' => 9 ), array( 'path' => '/a/', 'views' => 15, 'visits' => 7 ), array( 'path' => '/b/', 'views' => 3, 'visits' => 2 ) ) === $dd, 'per-path views and visits add across the halves, heaviest first' );
ok( false !== strpos( sn_analytics_drilldown_sql( 'country', array( 'US' ), '2026-09-20', '2026-10-19', 'human', 'sn_pageviews_v2', ' AND 1=1' ), 'GROUP BY path' ) && false === strpos( sn_analytics_drilldown_sql( 'country', array( 'US' ), '2026-09-20', '2026-10-19', 'human', 'sn_pageviews_v2', ' AND 1=1' ), '1=1' ), 'a range that is not a split bound never reaches the drilldown SQL' );

echo "\nGroup: session events\n";
reset_q( array( array( array( 'vid' => 'a', 'ts' => 1 ) ), array( array( 'vid' => 'b', 'ts' => 2 ), array( 'vid' => 'c', 'ts' => 3 ) ) ) );
$se = sn_analytics_fetch_session_events( '2026-09-20', '2026-10-19', 'human' );
ok( 2 === count( $GLOBALS['__q'] ) && false !== strpos( $GLOBALS['__q'][0], $OLD ) && false !== strpos( $GLOBALS['__q'][1], 'FROM sn_pageviews_v2' ) && false !== strpos( $GLOBALS['__q'][1], $NEW ), 'a crossing session read reads each generation for its side' );
ok( true === $se['configured'] && false === $se['capped'], 'the halves join, uncapped' );
$cap = sn_analytics_session_config()['row_cap'];
reset_q( array( array_fill( 0, $cap, array( 'vid' => 'a', 'ts' => 1 ) ), array() ) );
ok( true === sn_analytics_fetch_session_events( '2026-09-20', '2026-10-19', 'human' )['capped'], 'either half at the row cap is a capped read' );
reset_q( array( array(), null ) );
ok( false === sn_analytics_fetch_session_events( '2026-09-20', '2026-10-19', 'human' )['configured'], 'a failed half fails the read' );

echo "\nGroup: percentiles\n";
// Legacy: 10 at weight 1, 20 at weight 1. Second generation: 30 at 2, 40 at 1.
// Total 5. p50 threshold ceil(2.5)=3 -> 30; p75 ceil(3.75)=4 -> 30; p90 ceil(4.5)=5 -> 40.
reset_q( array( array( array( 'v' => 10, 'w' => 1 ), array( 'v' => 20, 'w' => 1 ) ), array( array( 'v' => 30, 'w' => 2 ), array( 'v' => 40, 'w' => 1 ) ) ) );
$pc = sn_analytics_percentiles( 'scroll', '2026-09-20', '2026-10-19', 'human' );
ok( 2 === count( $GLOBALS['__q'] ) && false !== strpos( $GLOBALS['__q'][0], 'GROUP BY v' ) && false === strpos( $GLOBALS['__q'][0], 'quantile' ) && false !== strpos( $GLOBALS['__q'][1], $NEW ), 'a crossing percentile read fetches each side\'s distribution, never a quantile' );
ok( array( 30.0, 30.0, 40.0 ) === array_column( (array) $pc, 'value' ), 'the merged distribution gives exact p50/p75/p90 (quantileExactWeighted rule)' );
ok( 10.0 === sn_analytics_weighted_quantile( array( array( 'v' => 10, 'w' => 1 ) ), 0.5 ) && null === sn_analytics_weighted_quantile( array(), 0.5 ), 'one value is its own quantile; an empty distribution has none' );
reset_q( array( array_fill( 0, SN_ANALYTICS_AE_ROW_CAP, array( 'v' => 1, 'w' => 1 ) ), array() ) );
ok( null === sn_analytics_percentiles( 'time', '2026-09-20', '2026-10-19', 'human' ), 'a half at the row cap is a partial distribution: no answer' );
ok( '' === sn_analytics_percentiles_dist_sql( 'sc', 'double1', '2026-09-20', '2026-10-19', 'human', 'sn_pageviews', ' AND 1=1' ), 'a distribution read without a split bound is refused' );
reset_q( array( array( array( 'p50' => 1.0, 'p75' => 2.0, 'p90' => 3.0 ) ) ) );
sn_analytics_percentiles( 'scroll', '2026-10-06', '2026-10-19', 'human' );
ok( 1 === count( $GLOBALS['__q'] ) && false !== strpos( $GLOBALS['__q'][0], 'quantileExactWeighted' ) && false !== strpos( $GLOBALS['__q'][0], 'FROM sn_pageviews_v2' ), 'a window after the split keeps the one quantile read' );

echo "\nGroup: bot signals\n";
ok( false !== strpos( sn_bot_signals_sql( 14, 'sn_pageviews', $OLD ), $OLD ) && false === strpos( sn_bot_signals_sql( 14, 'sn_pageviews', ' AND 1=1' ), '1=1' ), 'the signals read takes only a split bound' );
$dm = sn_bot_signals_days_merge( array( array( array( 'day' => '2026-10-05 00:00:00', 'n' => 3 ), array( 'day' => '2026-10-04 00:00:00', 'n' => 1 ) ), array( array( 'day' => '2026-10-05 00:00:00', 'n' => 4 ) ) ) );
ok( array( array( 'day' => '2026-10-05 00:00:00', 'n' => 7 ), array( 'day' => '2026-10-04 00:00:00', 'n' => 1 ) ) === $dm, 'the UTC day holding the split comes from both halves and counts once' );
ok( 2 === sn_bot_signals_readout( array(), $dm )['days_present'], 'so days present is not inflated' );

// Codex-preempt: two halves under the LIMIT each are not truncated together.
$GLOBALS['__opt'] = array();
function update_option( $k, $v ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
require __DIR__ . '/../inc/abilities-bot-signals.php';
sn_analytics_clock( strtotime( '2026-10-12 12:00:00 UTC' ) ); // a 14-day window from here crosses the clean day
$half = array_fill( 0, 3000, array( 'vid' => 'a' ) );
reset_q( array( $half, $half, array(), array() ) );
ok( true === sn_bot_signals_refresh() && false === $GLOBALS['__opt'][ SNT_BOT_SIGNAL_OPTION ]['truncated'] && 6000 === $GLOBALS['__opt'][ SNT_BOT_SIGNAL_OPTION ]['visitor_days'], 'two halves of 3,000 are 6,000 visitor-days and not truncated' );
reset_q( array( array_fill( 0, SNT_BOT_SIGNAL_LIMIT, array( 'vid' => 'a' ) ), array(), array(), array() ) );
sn_bot_signals_refresh();
ok( true === $GLOBALS['__opt'][ SNT_BOT_SIGNAL_OPTION ]['truncated'], 'a half at the LIMIT is truncated' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
