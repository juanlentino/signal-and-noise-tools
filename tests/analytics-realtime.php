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

// Durable option store (the views-today last-good survives the short transient).
$GLOBALS['__rt_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__rt_options'] ) ? $GLOBALS['__rt_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__rt_options'][ $key ] = $value;
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
	$GLOBALS['__rt_options']        = array();
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

// v9.26.4 — a valid IANA zone uses an EXACT local-midnight lower bound via AE's
// toStartOfInterval timezone arg (no PHP-computed elapsed, no now()/time() skew);
// an empty/invalid zone keeps the elapsed-seconds window (backward compatible).
$sqltz = sn_analytics_views_today_sql( 43200, 'America/New_York' );
ok( strpos( $sqltz, "timestamp >= toStartOfInterval(now(), INTERVAL '1' DAY, 'America/New_York')" ) !== false,
	'today sql: zoned lower bound is local midnight via toStartOfInterval' );
ok( strpos( $sqltz, 'SECOND' ) === false, 'today sql: zoned query drops the elapsed-seconds window' );
ok( strpos( $sqltz, "blob1 = 'pv' AND blob7 = 'human'" ) !== false, 'today sql: zoned query keeps the pv/human filter' );
$sqlno = sn_analytics_views_today_sql( 43200, "x'; DROP" );
ok( strpos( $sqlno, 'DROP' ) === false && strpos( $sqlno, "now() - INTERVAL '43200' SECOND" ) !== false,
	'today sql: an injectable zone is rejected → elapsed-seconds window' );

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

// ── Views today: durable last-good (survives the short transient) ──────────────
// The reported bug: "views today" flickered 55→40 because the 5-min realtime
// transient lapses between dashboard visits, and the widget then fell back to the
// UTC-day rollup bucket — a DIFFERENT day boundary than the site-timezone live
// query. The durable last-good keeps the SAME (site-local) definition on the cold
// path, keyed to the local day so it resets at local midnight, never regressing to
// the UTC bucket while a same-day measurement exists.
echo "\nGroup: views-today durable last-good\n";

// local_day helper: site-timezone Y-m-d (ET stub), injectable for determinism.
ok( function_exists( 'sn_analytics_local_day' ), 'local_day: helper exists' );
$et2 = new DateTimeZone( 'America/New_York' );
$late_et = ( new DateTimeImmutable( '2026-07-09 21:43:00', $et2 ) )->getTimestamp(); // 9:43pm ET = already 2026-07-10 in UTC
ok( sn_analytics_local_day( $late_et ) === '2026-07-09', 'local_day: 9:43pm ET is still the ET day, not the UTC day' );

$today_str = sn_analytics_local_day();

// Refresh persists a same-day durable last-good on a successful today query.
rt_reset();
$GLOBALS['__rt_query_return'] = array( array( 'class' => 'human', 'visitors' => 7 ) );
$GLOBALS['__rt_query_today']  = array( array( 'views' => 55 ) );
sn_analytics_realtime_refresh();
$lg = get_option( SN_ANALYTICS_VIEWS_TODAY_LASTGOOD );
ok( is_array( $lg ) && ( $lg['views'] ?? null ) === 55 && ( $lg['day'] ?? null ) === $today_str,
	'refresh: persists durable same-day last-good on success' );

// The core regression: transient warm (55), then it lapses — accessor still returns
// 55 from the durable same-day store, so the widget never flips to the UTC bucket.
delete_transient( SN_ANALYTICS_REALTIME_KEY );
ok( sn_analytics_views_today() === 55, 'accessor: lapsed transient → same-day last-good 55 (no UTC-bucket flip)' );

// A fresh transient still wins over the durable last-good.
rt_reset();
update_option( SN_ANALYTICS_VIEWS_TODAY_LASTGOOD, array( 'day' => $today_str, 'views' => 55 ), false );
set_transient( SN_ANALYTICS_REALTIME_KEY, array( 'counts' => array( 'human' => 1 ), 'views_today' => 60, 'fetched' => time() ), 0 );
ok( sn_analytics_views_today() === 60, 'accessor: fresh transient wins over durable last-good' );

// A stale (different-day) last-good is NOT shown — it resets at local midnight, so
// the accessor returns null and the caller falls back to the UTC bucket.
rt_reset();
update_option( SN_ANALYTICS_VIEWS_TODAY_LASTGOOD, array( 'day' => '2000-01-01', 'views' => 55 ), false );
ok( sn_analytics_views_today() === null, 'accessor: different-day last-good → null (stale, fall back)' );

// A failed today query must NOT clobber a good same-day durable value, and the
// accessor recovers via last-good despite the transient's views_today being null.
rt_reset();
update_option( SN_ANALYTICS_VIEWS_TODAY_LASTGOOD, array( 'day' => $today_str, 'views' => 55 ), false );
$GLOBALS['__rt_query_return'] = array( array( 'class' => 'human', 'visitors' => 7 ) );
$GLOBALS['__rt_query_today']  = null; // today query fails this cycle
sn_analytics_realtime_refresh();
ok( ( get_option( SN_ANALYTICS_VIEWS_TODAY_LASTGOOD )['views'] ?? null ) === 55,
	'refresh: failed today query does not clobber durable last-good' );
ok( sn_analytics_views_today() === 55, 'accessor: failed today refresh still shows same-day last-good, not null' );

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
