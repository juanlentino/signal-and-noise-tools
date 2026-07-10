<?php
/**
 * Tests for inc/analytics-realtime.php — the "visitors now" realtime tier.
 *
 * Covers:
 *   - sn_analytics_realtime_sql()      — distinct-visitor count over a minute window.
 *   - sn_analytics_realtime()          — read-only accessor: null vs cached int (0 is valid).
 *   - sn_analytics_realtime_refresh()  — query → cache; no-poison on failure; int clamp.
 *   - sn_analytics_realtime_warm()     — SWR scheduling decision (stale/fresh/scheduled/cap/unconfigured).
 *
 * Run: php tests/analytics-realtime.php
 *
 * @since plugin v5.0.1
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
// Defined by inc/analytics-api.php in production; this fixture doesn't load it.
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );

// ── WP + read-client stubs ───────────────────────────────────────────────────

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}

$GLOBALS['__rt_transients'] = array();
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__rt_transients'] ) ? $GLOBALS['__rt_transients'][ $key ] : false;
}
function set_transient( $key, $value, $exp = 0 ) {
	$GLOBALS['__rt_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__rt_transients'][ $key ] );
	return true;
}

$GLOBALS['__rt_scheduled']     = array();
$GLOBALS['__rt_single_events'] = array();
function wp_next_scheduled( $hook ) {
	return in_array( $hook, $GLOBALS['__rt_scheduled'], true ) ? ( time() + 100 ) : false;
}
function wp_schedule_single_event( $ts, $hook ) {
	$GLOBALS['__rt_single_events'][] = array( 'ts' => $ts, 'hook' => $hook );
	$GLOBALS['__rt_scheduled'][]     = $hook;
	return true;
}

$GLOBALS['__rt_cap'] = true;
function current_user_can( $cap ) {
	return (bool) $GLOBALS['__rt_cap'];
}

// Site timezone stub — fixed ET so the day-boundary math is deterministic.
function wp_timezone() {
	return new DateTimeZone( 'America/New_York' );
}

// AE read-client seam (analytics-api.php not loaded here; injected).
$GLOBALS['__rt_config_present'] = true;
$GLOBALS['__rt_query_return']   = null;  // visitors-now query result
$GLOBALS['__rt_query_today']    = null;  // views-today query result
$GLOBALS['__rt_query_calls']    = array();
function sn_analytics_config() {
	return $GLOBALS['__rt_config_present'] ? array( 'account_id' => 'a', 'token' => 't' ) : null;
}
function sn_analytics_query( $sql ) {
	$GLOBALS['__rt_query_calls'][] = $sql;
	// Route by shape: the views-today query is the only one that SUMs sampled PVs.
	if ( strpos( $sql, 'sum(_sample_interval)' ) !== false ) {
		return $GLOBALS['__rt_query_today'];
	}
	return $GLOBALS['__rt_query_return'];
}

require_once __DIR__ . '/../inc/analytics-realtime.php';

// ── Harness ──────────────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "PASS: $msg\n";
	} else {
		++$fail;
		echo "FAIL: $msg\n";
	}
}
function rt_reset() {
	$GLOBALS['__rt_transients']     = array();
	$GLOBALS['__rt_scheduled']      = array();
	$GLOBALS['__rt_single_events']  = array();
	$GLOBALS['__rt_cap']            = true;
	$GLOBALS['__rt_config_present'] = true;
	$GLOBALS['__rt_query_return']   = null;
	$GLOBALS['__rt_query_today']    = null;
	$GLOBALS['__rt_query_calls']    = array();
}

echo "Analytics realtime tier — plugin v5.0.1\n\n";

// ── SQL builder ───────────────────────────────────────────────────────────────
echo "Group: realtime SQL\n";
rt_reset();
$sql = sn_analytics_realtime_sql();
ok( strpos( $sql, 'count(DISTINCT index1) AS visitors' ) !== false, 'sql: counts distinct visitor hashes' );
ok( strpos( $sql, 'FROM sn_pageviews' ) !== false, 'sql: FROM the dataset' );
ok( preg_match( "/INTERVAL '5' MINUTE/", $sql ) === 1, 'sql: 5-minute "now" window' );
ok( strpos( $sql, 'blob7 AS class' ) !== false, 'sql: selects the class' );
ok( strpos( $sql, 'GROUP BY class' ) !== false, 'sql: groups visitors by class' );

// ── Accessor ──────────────────────────────────────────────────────────────────
echo "\nGroup: accessor\n";
rt_reset();
ok( sn_analytics_realtime() === null, 'accessor: null when never warmed' );

rt_reset();
set_transient( SN_ANALYTICS_REALTIME_KEY, array( 'counts' => array( 'human' => 7, 'bot' => 50 ), 'fetched' => time() ), 0 );
ok( sn_analytics_realtime() === 7, 'accessor: defaults to the human count' );
ok( sn_analytics_realtime( 'bot' ) === 50, 'accessor: explicit class returns that count' );
ok( sn_analytics_realtime( 'suspect' ) === 0, 'accessor: a class with no hits returns 0' );

rt_reset();
set_transient( SN_ANALYTICS_REALTIME_KEY, array( 'counts' => array( 'human' => 0 ), 'fetched' => time() ), 0 );
ok( sn_analytics_realtime() === 0, 'accessor: a warmed zero is 0, not null' );

// ── Refresh ───────────────────────────────────────────────────────────────────
echo "\nGroup: refresh\n";
rt_reset();
$GLOBALS['__rt_config_present'] = true;
$GLOBALS['__rt_query_return']   = array(
	array( 'class' => 'human', 'visitors' => 7 ),
	array( 'class' => 'bot',   'visitors' => 50 ),
);
sn_analytics_realtime_refresh();
ok( count( $GLOBALS['__rt_query_calls'] ) === 2, 'refresh: issues the visitors-now + views-today queries' );
$c = get_transient( SN_ANALYTICS_REALTIME_KEY );
ok( is_array( $c ) && ( $c['counts']['human'] ?? null ) === 7, 'refresh: caches per-class human count' );
ok( ( $c['counts']['bot'] ?? null ) === 50, 'refresh: caches per-class bot count' );
ok( isset( $c['fetched'] ) && is_int( $c['fetched'] ), 'refresh: stamps a fetched timestamp' );

rt_reset();
$GLOBALS['__rt_query_return'] = array( array( 'class' => 'human', 'visitors' => 0 ) );
sn_analytics_realtime_refresh();
ok( ( get_transient( SN_ANALYTICS_REALTIME_KEY )['counts']['human'] ?? null ) === 0, 'refresh: caches a real zero' );

rt_reset();
$GLOBALS['__rt_query_return'] = array( array( 'class' => 'human', 'visitors' => '42' ) );
sn_analytics_realtime_refresh();
ok( ( get_transient( SN_ANALYTICS_REALTIME_KEY )['counts']['human'] ?? null ) === 42, 'refresh: coerces numeric string to int' );

rt_reset();
$GLOBALS['__rt_query_return'] = array( array( 'class' => 'human', 'visitors' => -3 ) );
sn_analytics_realtime_refresh();
ok( ( get_transient( SN_ANALYTICS_REALTIME_KEY )['counts']['human'] ?? null ) === 0, 'refresh: clamps negative to 0' );

rt_reset();
set_transient( SN_ANALYTICS_REALTIME_KEY, array( 'counts' => array( 'human' => 9 ), 'fetched' => time() - 60 ), 0 );
$GLOBALS['__rt_config_present'] = true;
$GLOBALS['__rt_query_return']   = null;
sn_analytics_realtime_refresh();
ok( ( get_transient( SN_ANALYTICS_REALTIME_KEY )['counts']['human'] ?? null ) === 9, 'refresh: AE failure leaves prior counts intact' );

rt_reset();
$GLOBALS['__rt_query_return'] = array( array( 'wrong' => 1 ) );
sn_analytics_realtime_refresh();
ok( get_transient( SN_ANALYTICS_REALTIME_KEY ) === false, 'refresh: malformed AE rows → no write' );

// ── Warmer ────────────────────────────────────────────────────────────────────
echo "\nGroup: warmer\n";
// Stale (no cache) + capable + configured → schedule a single refresh.
rt_reset();
sn_analytics_realtime_warm();
ok( count( $GLOBALS['__rt_single_events'] ) === 1
	&& $GLOBALS['__rt_single_events'][0]['hook'] === SN_ANALYTICS_REALTIME_HOOK,
	'warmer: stale + capable + configured → schedules a refresh' );

// Fresh within 30s TTL → no schedule.
rt_reset();
set_transient( SN_ANALYTICS_REALTIME_KEY, array( 'value' => 3, 'fetched' => time() ), 0 );
sn_analytics_realtime_warm();
ok( count( $GLOBALS['__rt_single_events'] ) === 0, 'warmer: fresh within TTL → no schedule' );

// Stale but a refresh is already queued → no duplicate.
rt_reset();
$GLOBALS['__rt_scheduled'][] = SN_ANALYTICS_REALTIME_HOOK;
sn_analytics_realtime_warm();
ok( count( $GLOBALS['__rt_single_events'] ) === 0, 'warmer: already-scheduled → no duplicate' );

// Non-capable user → no schedule.
rt_reset();
$GLOBALS['__rt_cap'] = false;
sn_analytics_realtime_warm();
ok( count( $GLOBALS['__rt_single_events'] ) === 0, 'warmer: capability-gated' );

// Unconfigured → no schedule (don't warm a tier that can't return data).
rt_reset();
$GLOBALS['__rt_config_present'] = false;
sn_analytics_realtime_warm();
ok( count( $GLOBALS['__rt_single_events'] ) === 0, 'warmer: unconfigured → no schedule' );

// ── Views today (site-timezone day-so-far) ─────────────────────────────────────
echo "\nGroup: views-today boundary math (site timezone)\n";
$et = new DateTimeZone( 'America/New_York' );
$mid  = ( new DateTimeImmutable( '2026-07-09 00:00:00', $et ) )->getTimestamp();
$noon = ( new DateTimeImmutable( '2026-07-09 12:00:00', $et ) )->getTimestamp();
$eod  = ( new DateTimeImmutable( '2026-07-09 23:59:59', $et ) )->getTimestamp();
ok( sn_analytics_seconds_since_wp_midnight( $mid ) === 0, 'boundary: local midnight → 0s' );
ok( sn_analytics_seconds_since_wp_midnight( $noon ) === 43200, 'boundary: local noon → 43200s' );
ok( sn_analytics_seconds_since_wp_midnight( $eod ) === 86399, 'boundary: 1s before next local midnight → 86399s' );
// The prod symptom: 9:43pm ET is already tomorrow in UTC, yet elapsed-since-LOCAL
// -midnight is ~21.7h, NOT ~1.7h — proving the window follows ET, not UTC.
$late = ( new DateTimeImmutable( '2026-07-09 21:43:00', $et ) )->getTimestamp();
ok( sn_analytics_seconds_since_wp_midnight( $late ) === 78180, 'boundary: 9:43pm ET → 78180s (ET day), not a fresh UTC day' );

echo "\nGroup: views-today SQL\n";
$sqlt = sn_analytics_views_today_sql( 43200 );
ok( strpos( $sqlt, 'sum(_sample_interval) AS views' ) !== false, 'today sql: sums sampled pageviews' );
ok( strpos( $sqlt, 'FROM sn_pageviews' ) !== false, 'today sql: from the dataset' );
ok( strpos( $sqlt, "blob1 = 'pv'" ) !== false, 'today sql: pageviews only' );
ok( strpos( $sqlt, "blob7 = 'human'" ) !== false, 'today sql: human class only' );
ok( strpos( $sqlt, "now() - INTERVAL '43200' SECOND" ) !== false, 'today sql: window = seconds since local midnight' );
ok( strpos( $sqlt, "INTERVAL '-" ) === false, 'today sql: never a negative interval' );

echo "\nGroup: views-today accessor\n";
rt_reset();
ok( sn_analytics_views_today() === null, 'today accessor: null when unwarmed' );
set_transient( SN_ANALYTICS_REALTIME_KEY, array( 'counts' => array( 'human' => 1 ), 'views_today' => 0, 'fetched' => time() ), 0 );
ok( sn_analytics_views_today() === 0, 'today accessor: warmed zero is 0, not null' );
set_transient( SN_ANALYTICS_REALTIME_KEY, array( 'counts' => array( 'human' => 1 ), 'views_today' => 88, 'fetched' => time() ), 0 );
ok( sn_analytics_views_today() === 88, 'today accessor: returns the cached count' );
// Legacy cache shape (no key) → null so the widget falls back to the UTC bucket.
set_transient( SN_ANALYTICS_REALTIME_KEY, array( 'counts' => array( 'human' => 1 ), 'fetched' => time() ), 0 );
ok( sn_analytics_views_today() === null, 'today accessor: cache without the key → null (fallback)' );

echo "\nGroup: views-today refresh caching\n";
rt_reset();
$GLOBALS['__rt_query_return'] = array( array( 'class' => 'human', 'visitors' => 7 ) );
$GLOBALS['__rt_query_today']  = array( array( 'views' => 123 ) );
sn_analytics_realtime_refresh();
ok( ( get_transient( SN_ANALYTICS_REALTIME_KEY )['views_today'] ?? null ) === 123, 'refresh: caches views_today from the today query' );

// A successful-but-empty today query is a real zero (no views since local midnight).
rt_reset();
$GLOBALS['__rt_query_return'] = array( array( 'class' => 'human', 'visitors' => 5 ) );
$GLOBALS['__rt_query_today']  = array();
sn_analytics_realtime_refresh();
ok( get_transient( SN_ANALYTICS_REALTIME_KEY )['views_today'] === 0, 'refresh: empty today result → real 0' );

// A failed today query must NOT block the visitors-now cache; views_today → null (fallback).
rt_reset();
$GLOBALS['__rt_query_return'] = array( array( 'class' => 'human', 'visitors' => 5 ) );
$GLOBALS['__rt_query_today']  = null;
sn_analytics_realtime_refresh();
$c2 = get_transient( SN_ANALYTICS_REALTIME_KEY );
ok( ( $c2['counts']['human'] ?? null ) === 5, 'refresh: visitors cached even when the today query fails' );
ok( array_key_exists( 'views_today', $c2 ) && $c2['views_today'] === null, 'refresh: failed today query → views_today null' );

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
