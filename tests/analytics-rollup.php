<?php
/**
 * Tests for inc/analytics-rollup.php — the durable daily-rollup data layer.
 *
 * Exercises the pure-logic seams without a live DB / AE / WP:
 *   - sn_analytics_daily_schema_sql()  — dbDelta CREATE TABLE shape.
 *   - sn_analytics_rollup_sql( $days )  — AE SQL builder (event-type-correct
 *                                          conditional aggregation, $days cast).
 *   - sn_analytics_rollup_upsert()      — AE rows → batched ON DUPLICATE KEY UPDATE.
 *   - sn_analytics_run_rollup()         — orchestration: query → upsert → fresh stamp;
 *                                          no-op when AE not configured (query → null).
 *   - sn_analytics_daily_range()        — read accessor: range filter + type normalize.
 *   - sn_analytics_rollup_warm()        — SWR warmer scheduling decision (stale/fresh/
 *                                          already-scheduled/cap-gated).
 *   - sn_analytics_rollup_schedule()    — idempotent daily backstop registration.
 *   - sn_analytics_daily_maybe_install()— version-gated install dispatch.
 *
 * Run: php tests/analytics-rollup.php
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
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
// Defined by inc/analytics-api.php in production (the read-client owns it); this
// fixture doesn't load that file, so provide it for the rollup SQL builder.
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
if ( ! defined( 'OBJECT' )   ) { define( 'OBJECT',   'OBJECT' ); }
if ( ! defined( 'ARRAY_A' )  ) { define( 'ARRAY_A',  'ARRAY_A' ); }

// ── WP function stubs ────────────────────────────────────────────────────────

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}

// Options.
$GLOBALS['__ar_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__ar_options'] ) ? $GLOBALS['__ar_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__ar_options'][ $key ] = $value;
	return true;
}

// Transients.
$GLOBALS['__ar_transients'] = array();
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__ar_transients'] ) ? $GLOBALS['__ar_transients'][ $key ] : false;
}
function set_transient( $key, $value, $exp = 0 ) {
	$GLOBALS['__ar_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__ar_transients'][ $key ] );
	return true;
}

// Cron scheduling — capture calls.
$GLOBALS['__ar_scheduled']        = array(); // hooks with a live next-run
$GLOBALS['__ar_single_events']    = array();
$GLOBALS['__ar_recurring_events'] = array();
function wp_next_scheduled( $hook ) {
	return in_array( $hook, $GLOBALS['__ar_scheduled'], true ) ? ( time() + 100 ) : false;
}
function wp_schedule_single_event( $ts, $hook ) {
	$GLOBALS['__ar_single_events'][] = array( 'ts' => $ts, 'hook' => $hook );
	$GLOBALS['__ar_scheduled'][]     = $hook;
	return true;
}
function wp_schedule_event( $ts, $recurrence, $hook ) {
	$GLOBALS['__ar_recurring_events'][] = array( 'ts' => $ts, 'recurrence' => $recurrence, 'hook' => $hook );
	$GLOBALS['__ar_scheduled'][]        = $hook;
	return true;
}

// Capability gate.
$GLOBALS['__ar_cap'] = true;
function current_user_can( $cap ) {
	return (bool) $GLOBALS['__ar_cap'];
}

// dbDelta — defined so the module's install() skips the upgrade.php require.
$GLOBALS['__ar_dbdelta_calls'] = array();
function dbDelta( $sql ) {
	$GLOBALS['__ar_dbdelta_calls'][] = $sql;
	return array();
}

// ── AE read-client seam (analytics-api.php is NOT loaded here; we inject) ─────
// run_rollup() depends on sn_analytics_query() + sn_analytics_config(); in
// production the main loader requires analytics-api.php first. Here we stub
// them so we can drive run_rollup's orchestration deterministically.
$GLOBALS['__ar_query_return']  = null;  // what sn_analytics_query() returns
$GLOBALS['__ar_query_calls']   = array();
$GLOBALS['__ar_config_present'] = true;
function sn_analytics_config() {
	return $GLOBALS['__ar_config_present']
		? array( 'account_id' => 'acct', 'token' => 'tok' )
		: null;
}
function sn_analytics_query( $sql ) {
	$GLOBALS['__ar_query_calls'][] = $sql;
	return $GLOBALS['__ar_query_return'];
}

// ── wpdb stub ────────────────────────────────────────────────────────────────
class AR_Stub_wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $queries    = array();      // recorded raw SQL
	public $rows       = array();       // table => list of row arrays (for SELECT)

	public function get_charset_collate() {
		return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		// Honor the PLACEHOLDER type (like real $wpdb->prepare), not the arg's
		// PHP type — so a %f→%d mutation (float truncation) is observable.
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? '';
			++$i;
			switch ( $m[0] ) {
				case '%d':
					return (string) (int) $a;
				case '%f':
					return (string) (float) $a;
				default:
					return "'" . addslashes( (string) $a ) . "'";
			}
		}, $query );
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		// Fail-mode lets a test exercise the write-failure accounting path.
		return ! empty( $GLOBALS['__ar_query_fail'] ) ? false : 1;
	}

	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql; // record the SELECT so its real clauses are assertable
		// daily_range SELECT: "... FROM wp_sn_analytics_daily WHERE day >= '<from>' AND day <= '<to>' ORDER BY ..."
		if ( ! preg_match( '/FROM\s+(\S+)/', $sql, $tm ) ) {
			return array();
		}
		$rows = isset( $this->rows[ $tm[1] ] ) ? $this->rows[ $tm[1] ] : array();
		if ( preg_match( "/day >= '([^']*)'/", $sql, $fm ) ) {
			$from = $fm[1];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $from ) {
				return (string) $r['day'] >= $from;
			} ) );
		}
		if ( preg_match( "/day <= '([^']*)'/", $sql, $to_m ) ) {
			$to = $to_m[1];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $to ) {
				return (string) $r['day'] <= $to;
			} ) );
		}
		if ( preg_match( "/class = '([^']*)'/", $sql, $cm ) ) {
			$cls  = $cm[1];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $cls ) {
				return (string) ( $r['class'] ?? 'human' ) === $cls;
			} ) );
		}
		// GROUP BY class → return per-class SUM(views)/SUM(visits) rows.
		if ( stripos( $sql, 'GROUP BY class' ) !== false ) {
			$agg = array();
			foreach ( $rows as $r ) {
				$c = (string) ( $r['class'] ?? 'human' );
				if ( ! isset( $agg[ $c ] ) ) { $agg[ $c ] = array( 'class' => $c, 'views' => 0, 'visits' => 0 ); }
				$agg[ $c ]['views']  += (int) $r['views'];
				$agg[ $c ]['visits'] += (int) $r['visits'];
			}
			return array_values( $agg );
		}
		usort( $rows, function ( $a, $b ) {
			$cmp = strcmp( (string) $b['day'], (string) $a['day'] );
			return 0 !== $cmp ? $cmp : ( (int) $b['views'] - (int) $a['views'] );
		} );
		return $rows;
	}
}

$GLOBALS['wpdb'] = new AR_Stub_wpdb();

// P3 wire: run_rollup must drive the dims roll when the function exists.
$GLOBALS['__ar_dims_called'] = 0;
function sn_analytics_dims_run_rollup() { $GLOBALS['__ar_dims_called']++; }

// ── Load the module under test ───────────────────────────────────────────────
require_once __DIR__ . '/../inc/analytics-rollup.php';

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
function ar_reset() {
	$GLOBALS['__ar_options']           = array();
	$GLOBALS['__ar_transients']        = array();
	$GLOBALS['__ar_scheduled']         = array();
	$GLOBALS['__ar_single_events']     = array();
	$GLOBALS['__ar_recurring_events']  = array();
	$GLOBALS['__ar_cap']               = true;
	$GLOBALS['__ar_query_return']      = null;
	$GLOBALS['__ar_query_calls']       = array();
	$GLOBALS['__ar_config_present']    = true;
	$GLOBALS['__ar_dbdelta_calls']     = array();
	$GLOBALS['__ar_query_fail']        = false;
	$GLOBALS['__ar_dims_called']       = 0;
	$GLOBALS['wpdb']                   = new AR_Stub_wpdb();
}

echo "Analytics daily-rollup data layer — plugin v5.0.1\n\n";

// ── Schema SQL ────────────────────────────────────────────────────────────────
echo "Group: schema SQL\n";
ar_reset();
$schema = sn_analytics_daily_schema_sql();
ok( is_string( $schema ) && '' !== $schema, 'schema: returns a non-empty string' );
ok( strpos( $schema, 'wp_sn_analytics_daily' ) !== false, 'schema: targets the prefixed table name' );
ok( strpos( $schema, 'PRIMARY KEY  (id)' ) !== false, 'schema: PRIMARY KEY has the dbDelta two-space form' );
ok( strpos( $schema, 'UNIQUE KEY' ) !== false, 'schema: declares a UNIQUE KEY' );
foreach ( array( 'day', 'path', 'views', 'visits', 'scroll_avg', 'time_avg' ) as $col ) {
	ok( preg_match( '/\b' . $col . '\b/', $schema ) === 1, "schema: declares the $col column" );
}
ok( strpos( $schema, 'utf8mb4' ) !== false, 'schema: includes the charset collate' );
ok( preg_match( '/\bclass\b/', $schema ) === 1, 'schema: declares the class column' );
ok( preg_match( '/UNIQUE KEY\s+\w+\s*\(\s*day\s*,\s*path\s*,\s*class\s*\)/', $schema ),
	'schema: UNIQUE KEY is now (day, path, class)' );
ok( strpos( $schema, 'VARCHAR(180)' ) !== false, 'schema: path shrunk to 180 so the 3-col key fits 767 bytes' );

// ── Rollup SQL builder ────────────────────────────────────────────────────────
echo "\nGroup: rollup SQL builder\n";
ar_reset();
$sql = sn_analytics_rollup_sql( 7 );
ok( strpos( $sql, 'FROM sn_pageviews' ) !== false, 'rollup-sql: FROM the sn_pageviews dataset' );
ok( strpos( $sql, "sumIf(_sample_interval, blob1 = 'pv')" ) !== false, 'rollup-sql: views = sumIf(_sample_interval, pv)' );
ok( strpos( $sql, 'count(DISTINCT index1)' ) !== false, 'rollup-sql: visits = count(DISTINCT index1)' );
ok( strpos( $sql, "avgIf(double1, blob1 = 'sc')" ) !== false, 'rollup-sql: scroll_avg = avgIf(double1, sc)' );
ok( strpos( $sql, "avgIf(double2, blob1 = 'tm')" ) !== false, 'rollup-sql: time_avg = avgIf(double2, tm)' );
ok( strpos( $sql, "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d')" ) !== false, 'rollup-sql: day-bucket via toStartOfDay+formatDateTime' );
ok( strpos( $sql, 'blob2 AS path' ) !== false, 'rollup-sql: path = blob2' );
ok( strpos( $sql, 'blob7 AS class' ) !== false, 'rollup-sql: selects blob7 AS class' );
ok( preg_match( "/INTERVAL '7' DAY/", $sql ) === 1, 'rollup-sql: window uses the $days arg' );
ok( strpos( $sql, "toStartOfDay(now() - INTERVAL '7' DAY)" ) !== false,
	'rollup-sql: window lower bound is floored to a day boundary (oldest bucket is a complete day)' );
ok( strpos( $sql, 'GROUP BY day, path, class' ) !== false, 'rollup-sql: groups by day, path AND class' );
// Injection guard — $days must be integer-cast, never interpolated raw.
$evil = sn_analytics_rollup_sql( "7; DROP TABLE x" );
ok( strpos( $evil, 'DROP TABLE' ) === false && preg_match( "/INTERVAL '7' DAY/", $evil ) === 1,
	'rollup-sql: $days is integer-cast (no SQL injection)' );
$zero = sn_analytics_rollup_sql( 0 );
ok( preg_match( "/INTERVAL '1' DAY/", $zero ) === 1, 'rollup-sql: a non-positive window floors to 1 day' );

// ── Upsert ────────────────────────────────────────────────────────────────────
echo "\nGroup: upsert\n";
ar_reset();
$rows = array(
	array( 'day' => '2026-06-11', 'path' => '/notes/a', 'class' => 'human', 'views' => '42', 'visits' => '30', 'scroll_avg' => '58.5', 'time_avg' => '12345' ),
	array( 'day' => '2026-06-11', 'path' => '/',        'class' => 'bot',   'views' => 100,  'visits' => 80,   'scroll_avg' => 40,     'time_avg' => 5000 ),
);
$n = sn_analytics_rollup_upsert( $rows );
ok( 2 === $n, 'upsert: returns the number of rows written' );
$wpdb = $GLOBALS['wpdb'];
ok( count( $wpdb->queries ) === 1, 'upsert: writes a single batched query for both rows' );
$q = $wpdb->queries[0];
ok( stripos( $q, 'INSERT INTO wp_sn_analytics_daily' ) !== false, 'upsert: INSERT into the table' );
ok( stripos( $q, 'ON DUPLICATE KEY UPDATE' ) !== false, 'upsert: uses ON DUPLICATE KEY UPDATE' );
// Full VALUES tuple in EXACT column order — distinct sentinels pin both
// position and per-column binding (catches swapped columns). scroll_avg/time_avg
// are number_format()'d '.'-decimal strings bound via %s, so they read as quoted
// '58.50' / '12345.00', not raw %f floats.
ok( strpos( $q, "'2026-06-11', '/notes/a', 'human', 42, 30, '58.50', '12345.00'" ) !== false,
	'upsert: binds (day, path, class, views, visits, scroll_avg, time_avg) in exact order' );
// Every metric column is refreshed on conflict, not just views — this is the
// recomputed-partial-day self-correction guarantee.
foreach ( array( 'views', 'visits', 'scroll_avg', 'time_avg' ) as $col ) {
	ok( strpos( $q, "{$col}=VALUES({$col})" ) !== false, "upsert: ON DUPLICATE refreshes $col" );
}

// Malformed rows are skipped, not written.
ar_reset();
$bad = array(
	array( 'path' => '/no-day', 'views' => 1 ),                // missing day
	array( 'day' => '2026-06-11', 'views' => 1 ),              // missing path
	array( 'day' => 'not-a-date', 'path' => '/x', 'views' => 1 ), // malformed day
);
$n = sn_analytics_rollup_upsert( $bad );
ok( 0 === $n, 'upsert: skips rows missing day/path or with a malformed day' );
ok( count( $GLOBALS['wpdb']->queries ) === 0, 'upsert: no query issued when every row is malformed' );

// Empty input → no query.
ar_reset();
ok( 0 === sn_analytics_rollup_upsert( array() ), 'upsert: empty input returns 0' );
ok( count( $GLOBALS['wpdb']->queries ) === 0, 'upsert: empty input issues no query' );

// Value normalization: negative views/visits clamp to 0, averages round to 2dp,
// and an over-long path truncates to 180 chars.
ar_reset();
$long_path = '/' . str_repeat( 'a', 250 );
sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => $long_path, 'views' => -5, 'visits' => -1, 'scroll_avg' => '58.567', 'time_avg' => '12345.6789' ),
) );
$qn = $GLOBALS['wpdb']->queries[0];
ok( strpos( $qn, "'/" . str_repeat( 'a', 179 ) . "'" ) !== false && strpos( $qn, str_repeat( 'a', 181 ) ) === false,
	'upsert: path truncated to 180 chars' );
ok( strpos( $qn, ", 0, 0, '58.57', '12345.68'" ) !== false,
	'upsert: negative counts clamp to 0; averages round to 2 decimals' );

// Unknown class is rejected (never stored; defensive allow-list).
ar_reset();
$n = sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => '/x', 'class' => 'martian', 'views' => 1, 'visits' => 1, 'scroll_avg' => 0, 'time_avg' => 0 ),
) );
ok( 0 === $n, 'upsert: a row with an unknown class is skipped' );

// Missing class defaults to human.
ar_reset();
sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => '/x', 'views' => 1, 'visits' => 1, 'scroll_avg' => 0, 'time_avg' => 0 ),
) );
ok( strpos( $GLOBALS['wpdb']->queries[0], "'/x', 'human'" ) !== false, 'upsert: a row with no class defaults to human' );

// A failed $wpdb->query() (false) must NOT be counted as written.
ar_reset();
$GLOBALS['__ar_query_fail'] = true;
$nf = sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => '/x', 'views' => 1, 'visits' => 1, 'scroll_avg' => 1, 'time_avg' => 1 ),
) );
ok( 0 === $nf, 'upsert: a failed write (query returns false) is not counted' );

// ── Locale-safe float binding (regression) ────────────────────────────────────
// $wpdb->prepare() routes %f through vsprintf(), which is LC_NUMERIC-sensitive:
// under a comma-decimal server locale (de_DE, pt_BR, …) a raw-float %f renders
// 58.5 as "58,5" — corrupt SQL. scroll_avg/time_avg must therefore be bound as
// '.'-decimal strings (number_format → %s), never as %f floats, so the generated
// SQL is identical regardless of the server's LC_NUMERIC.
echo "\nGroup: locale-safe float binding\n";
$__saved_numeric = setlocale( LC_NUMERIC, '0' ); // query current, for restore
setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'de_DE.ISO8859-1' ); // no-op if uninstalled
ar_reset();
sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => '/x', 'class' => 'human', 'views' => 1, 'visits' => 1, 'scroll_avg' => 58.5, 'time_avg' => 12345.5 ),
) );
$ql = $GLOBALS['wpdb']->queries[0];
ok( strpos( $ql, "'58.50'" ) !== false,
	'locale-safe: scroll_avg bound as a dot-decimal 2dp string (%s), not a %f float' );
ok( strpos( $ql, "'12345.50'" ) !== false,
	'locale-safe: time_avg bound as a dot-decimal string with NO thousands separator' );
ok( strpos( $ql, '58,5' ) === false && strpos( $ql, '12345,5' ) === false && strpos( $ql, '12,345' ) === false,
	'locale-safe: no comma decimal or thousands comma under a de_DE LC_NUMERIC' );
if ( false !== $__saved_numeric ) { setlocale( LC_NUMERIC, $__saved_numeric ); }

// ── run_rollup orchestration ──────────────────────────────────────────────────
echo "\nGroup: run_rollup\n";
ar_reset();
$GLOBALS['__ar_config_present'] = true;
$GLOBALS['__ar_query_return']   = array(
	array( 'day' => '2026-06-11', 'path' => '/', 'views' => 9, 'visits' => 7, 'scroll_avg' => 50, 'time_avg' => 3000 ),
);
sn_analytics_run_rollup();
ok( count( $GLOBALS['__ar_query_calls'] ) === 1, 'run_rollup: issues exactly one AE query' );
ok( count( $GLOBALS['wpdb']->queries ) === 1, 'run_rollup: upserts the returned rows' );
ok( get_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY ) !== false, 'run_rollup: stamps the freshness transient on success' );
ok( $GLOBALS['__ar_dims_called'] === 1, 'run_rollup: drives the dims roll on a configured success' );

// Not configured → AE query returns null → no upsert, no fresh stamp.
ar_reset();
$GLOBALS['__ar_config_present'] = false;
$GLOBALS['__ar_query_return']   = null;
sn_analytics_run_rollup();
ok( count( $GLOBALS['wpdb']->queries ) === 0, 'run_rollup: no upsert when AE is not configured' );
ok( get_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY ) === false, 'run_rollup: no freshness stamp when unconfigured' );
ok( $GLOBALS['__ar_dims_called'] === 0, 'run_rollup: skips the dims roll when unconfigured' );

// Configured but AE returns an empty set → no upsert, but still stamps fresh
// (a successful "nothing happened today" must not re-fire every 15 min).
ar_reset();
$GLOBALS['__ar_config_present'] = true;
$GLOBALS['__ar_query_return']   = array();
sn_analytics_run_rollup();
ok( count( $GLOBALS['wpdb']->queries ) === 0, 'run_rollup: empty AE result → no upsert' );
ok( get_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY ) !== false, 'run_rollup: empty-but-successful result still stamps fresh' );

// Configured but the AE query FAILS (null: transport / non-200 / parse error).
// Distinct from the config gate above — this exercises the !is_array null-guard.
// Must NOT stamp fresh, so the warmer keeps retrying rather than treating a
// failure as a successful idle day.
ar_reset();
$GLOBALS['__ar_config_present'] = true;
$GLOBALS['__ar_query_return']   = null;
sn_analytics_run_rollup();
ok( count( $GLOBALS['__ar_query_calls'] ) === 1, 'run_rollup: a configured failure still issued the AE query' );
ok( count( $GLOBALS['wpdb']->queries ) === 0, 'run_rollup: AE failure (null) → no upsert' );
ok( get_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY ) === false, 'run_rollup: AE failure (null) → NOT stamped fresh (warmer retries)' );

// ── daily_range read accessor ─────────────────────────────────────────────────
echo "\nGroup: daily_range\n";
ar_reset();
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'id' => 1, 'day' => '2026-06-09', 'path' => '/',        'class' => 'human', 'views' => '10', 'visits' => '8',  'scroll_avg' => '33.3', 'time_avg' => '2000' ),
	array( 'id' => 2, 'day' => '2026-06-11', 'path' => '/notes/a', 'class' => 'human', 'views' => '42', 'visits' => '30', 'scroll_avg' => '58.5', 'time_avg' => '12345' ),
	array( 'id' => 3, 'day' => '2026-06-11', 'path' => '/notes/a', 'class' => 'bot',   'views' => '500','visits' => '5',  'scroll_avg' => '0',    'time_avg' => '0' ),
);
$human = sn_analytics_daily_range( '2026-06-08', '2026-06-12' ); // default class = human
ok( count( $human ) === 2, 'daily_range: defaults to human, excludes the bot row' );
ok( strpos( end( $GLOBALS['wpdb']->queries ), "class = 'human'" ) !== false, 'daily_range: SQL filters class = human by default' );

$bots = sn_analytics_daily_range( '2026-06-08', '2026-06-12', 'bot' );
ok( count( $bots ) === 1 && $bots[0]['views'] === 500, 'daily_range: explicit class returns that bucket' );

// human[0] is the 2026-06-11 /notes/a row (newest day, highest views in the human bucket).
ok( ( $human[0]['day'] ?? '' ) === '2026-06-11', 'daily_range: newest day first' );
ok( is_int( $human[0]['views'] ?? null ) && $human[0]['views'] === 42, 'daily_range: views normalized to int' );
ok( is_float( $human[0]['scroll_avg'] ?? null ), 'daily_range: scroll_avg normalized to float' );
ok( ( $human[0]['path'] ?? '' ) === '/notes/a', 'daily_range: path preserved' );
// Pin the PRODUCTION SQL clauses (not the stub's reimplemented filter), so a
// broken upper bound or flipped sort can't ship green through the stub.
$range_sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $range_sql, 'day >= ' ) !== false && strpos( $range_sql, 'day <= ' ) !== false,
	'daily_range: SQL applies BOTH the lower and upper day bound' );
ok( strpos( $range_sql, 'ORDER BY day DESC' ) !== false,
	'daily_range: SQL orders newest day first' );

// ── class totals accessor ─────────────────────────────────────────────────────
echo "\nGroup: class totals\n";
ar_reset();
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'day' => '2026-06-11', 'path' => '/', 'class' => 'human',   'views' => '40', 'visits' => '30', 'scroll_avg' => 0, 'time_avg' => 0 ),
	array( 'day' => '2026-06-11', 'path' => '/', 'class' => 'bot',     'views' => '500','visits' => '5',  'scroll_avg' => 0, 'time_avg' => 0 ),
	array( 'day' => '2026-06-11', 'path' => '/', 'class' => 'suspect', 'views' => '12', 'visits' => '4',  'scroll_avg' => 0, 'time_avg' => 0 ),
);
$tot = sn_analytics_class_totals( '2026-06-08', '2026-06-12' );
ok( ( $tot['human']['views'] ?? null ) === 40, 'class_totals: human views summed' );
ok( ( $tot['bot']['views'] ?? null ) === 500, 'class_totals: bot views summed' );
ok( ( $tot['suspect']['visits'] ?? null ) === 4, 'class_totals: suspect visits summed' );

// ── SWR warmer scheduling decision ────────────────────────────────────────────
echo "\nGroup: warmer\n";
// Stale (no fresh stamp) + capable user → schedules a single rollup event.
ar_reset();
$GLOBALS['__ar_cap'] = true;
sn_analytics_rollup_warm();
ok( count( $GLOBALS['__ar_single_events'] ) === 1
	&& $GLOBALS['__ar_single_events'][0]['hook'] === SN_ANALYTICS_ROLLUP_HOOK,
	'warmer: stale + capable → schedules the rollup hook' );

// Fresh stamp within TTL → no schedule.
ar_reset();
set_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY, time(), 0 );
sn_analytics_rollup_warm();
ok( count( $GLOBALS['__ar_single_events'] ) === 0, 'warmer: fresh within TTL → no schedule' );

// Stale but an event is already queued → no duplicate schedule.
ar_reset();
$GLOBALS['__ar_scheduled'][] = SN_ANALYTICS_ROLLUP_HOOK;
sn_analytics_rollup_warm();
ok( count( $GLOBALS['__ar_single_events'] ) === 0, 'warmer: already-scheduled → no duplicate' );

// REGRESSION: the daily backstop must NOT block the on-demand warmer. They use
// distinct hooks — otherwise the always-scheduled recurring event makes
// wp_next_scheduled() permanently truthy and the warmer never fires (the 15-min
// SWR freshness would silently degrade to once-daily).
ar_reset();
$GLOBALS['__ar_scheduled'][] = SN_ANALYTICS_ROLLUP_DAILY_HOOK;
sn_analytics_rollup_warm();
ok( count( $GLOBALS['__ar_single_events'] ) === 1
	&& $GLOBALS['__ar_single_events'][0]['hook'] === SN_ANALYTICS_ROLLUP_HOOK,
	'warmer: a scheduled daily backstop does not block the single-event warmer' );

// Non-capable user → never schedules (no warming work for users who can't see stats).
ar_reset();
$GLOBALS['__ar_cap'] = false;
sn_analytics_rollup_warm();
ok( count( $GLOBALS['__ar_single_events'] ) === 0, 'warmer: capability-gated' );

// ── daily backstop scheduling ─────────────────────────────────────────────────
echo "\nGroup: backstop schedule\n";
ar_reset();
sn_analytics_rollup_schedule();
ok( count( $GLOBALS['__ar_recurring_events'] ) === 1
	&& $GLOBALS['__ar_recurring_events'][0]['recurrence'] === 'daily'
	&& $GLOBALS['__ar_recurring_events'][0]['hook'] === SN_ANALYTICS_ROLLUP_DAILY_HOOK,
	'schedule: registers a daily recurring rollup on its OWN hook when none exists' );
// Idempotent — already scheduled → no second registration.
ar_reset();
$GLOBALS['__ar_scheduled'][] = SN_ANALYTICS_ROLLUP_DAILY_HOOK;
sn_analytics_rollup_schedule();
ok( count( $GLOBALS['__ar_recurring_events'] ) === 0, 'schedule: idempotent when already scheduled' );

// ── maybe_install version gate ────────────────────────────────────────────────
echo "\nGroup: maybe_install\n";
// Option already current → install (dbDelta) NOT called.
ar_reset();
update_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT, SN_ANALYTICS_DAILY_DB_VERSION );
sn_analytics_daily_maybe_install();
ok( count( $GLOBALS['__ar_dbdelta_calls'] ) === 0, 'maybe_install: current version → no dbDelta' );
ok( SN_ANALYTICS_DAILY_DB_VERSION === '2', 'db version is 2 (class dimension added)' );

// Upgrading from v1 drops the old table (dbDelta cannot rotate the unique key)
// then recreates. The stub records the DROP via query().
ar_reset();
update_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT, '1' );
sn_analytics_daily_maybe_install();
$dropped = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( stripos( $q, 'DROP TABLE IF EXISTS wp_sn_analytics_daily' ) !== false ) { $dropped = true; }
}
ok( $dropped, 'maybe_install: v1→v2 drops the old table before recreating' );
ok( count( $GLOBALS['__ar_dbdelta_calls'] ) === 1, 'maybe_install: v1→v2 runs dbDelta to recreate' );
ok( get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT ) === '2', 'maybe_install: stamps db version 2' );

// Option absent → install runs dbDelta with the schema + stamps the version.
ar_reset();
sn_analytics_daily_maybe_install();
ok( count( $GLOBALS['__ar_dbdelta_calls'] ) === 1, 'maybe_install: missing version → runs dbDelta' );
ok( strpos( $GLOBALS['__ar_dbdelta_calls'][0], 'wp_sn_analytics_daily' ) !== false, 'maybe_install: dbDelta gets the CREATE TABLE' );
ok( get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT ) === SN_ANALYTICS_DAILY_DB_VERSION, 'maybe_install: stamps the db version option' );

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
