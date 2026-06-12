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

// AE read-client seam (analytics-api.php not loaded here; injected).
$GLOBALS['__rt_config_present'] = true;
$GLOBALS['__rt_query_return']   = null;
$GLOBALS['__rt_query_calls']    = array();
function sn_analytics_config() {
	return $GLOBALS['__rt_config_present'] ? array( 'account_id' => 'a', 'token' => 't' ) : null;
}
function sn_analytics_query( $sql ) {
	$GLOBALS['__rt_query_calls'][] = $sql;
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
ok( count( $GLOBALS['__rt_query_calls'] ) === 1, 'refresh: issues one AE query' );
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

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
