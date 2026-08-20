<?php
/**
 * Tests for inc/analytics-read.php — dashboard read accessors over the path table.
 * Run: php tests/analytics-read.php
 * @since plugin v5.0.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_DAILY_TABLE', 'sn_analytics_daily' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

// Options (Phase A Task 4): sn_analytics_range_totals() reads the
// sn_analytics_exact_metrics_since discontinuity marker, and the read-side
// integrity guard reads + writes sn_analytics_integrity_alert. A write COUNTER
// makes guard idempotence assertable as a call count (mutation-resistant),
// not a label the fixture supplies for free.
$GLOBALS['__rd_options']       = array();
$GLOBALS['__rd_option_writes'] = 0;
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__rd_options'] ) ? $GLOBALS['__rd_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__rd_options'][ $key ] = $value;
	++$GLOBALS['__rd_option_writes'];
	return true;
}

class RD_Stub_wpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array();
	// Models the real wpdb error channel: a failed query sets last_error and
	// (for ARRAY_A) get_results returns an EMPTY array — indistinguishable from
	// a genuinely empty result WITHOUT checking last_error. That transport
	// transform is exactly what the failed-read group exercises.
	public $last_error = '';
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? ''; ++$i;
			switch ( $m[0] ) { case '%d': return (string) (int) $a; case '%f': return (string) (float) $a; default: return "'" . addslashes( (string) $a ) . "'"; }
		}, $query );
	}
	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		if ( '' !== $this->last_error ) { return array(); } // real wpdb: failed query → last_error set, empty ARRAY_A result.
		// `FROM` also appears INSIDE TRIM(TRAILING '/' FROM path), so the first
		// match is not necessarily the table. Resolve against a table this mock
		// actually holds rather than trusting position.
		if ( ! preg_match_all( '/FROM\s+(\S+)/', $sql, $tm ) ) { return array(); }
		$rows = array();
		foreach ( $tm[1] as $cand ) {
			if ( isset( $this->rows[ $cand ] ) ) { $rows = $this->rows[ $cand ]; break; }
		}
		if ( preg_match( "/class = '([^']*)'/", $sql, $cm ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $cm ) { return (string) ( $r['class'] ?? 'human' ) === $cm[1]; } ) );
		}
		// GROUP BY path → per-path views-weighted aggregate.
		//
		// The mock READS WHICH KEY the SQL asked for; it must never assume. A
		// mock that merges `/notes` and `/notes/` on its own initiative would
		// report a merge the database is not performing — the exact shape of
		// lie that shipped three invented payload shapes in one file.
		// Read it out of the GROUP BY CLAUSE specifically. Keying off the
		// expression appearing ANYWHERE in the SQL made this mock merge rows
		// that a `GROUP BY path` query would have returned split — it survived
		// the mutation that reverted exactly this bug.
		$canonical = 1 === preg_match( '/GROUP BY\s+CASE\b/i', $sql );
		if ( $canonical || stripos( $sql, 'GROUP BY path' ) !== false ) {
			$agg = array();
			foreach ( $rows as $r ) {
				$p = $canonical ? sn_analytics_canonical_path( (string) $r['path'] ) : (string) $r['path'];
				if ( ! isset( $agg[ $p ] ) ) { $agg[ $p ] = array( 'path' => $p, 'views' => 0, 'visits' => 0, 'sw' => 0.0, 'tw' => 0.0 ); }
				$agg[ $p ]['views']  += (int) $r['views'];
				$agg[ $p ]['visits'] += (int) $r['visits'];
				$agg[ $p ]['sw']     += (float) $r['scroll_avg'] * (int) $r['views'];
				$agg[ $p ]['tw']     += (float) $r['time_avg'] * (int) $r['views'];
			}
			$out = array();
			foreach ( $agg as $a ) {
				$out[] = array( 'path' => $a['path'], 'views' => $a['views'], 'visits' => $a['visits'],
					'scroll_avg' => $a['views'] ? $a['sw'] / $a['views'] : 0, 'time_avg' => $a['views'] ? $a['tw'] / $a['views'] : 0 );
			}
			usort( $out, function ( $x, $y ) { return (int) $y['views'] - (int) $x['views']; } );
			// LIMIT is modelled because it is LOAD-BEARING for this fix: the
			// database orders and truncates on the GROUPED figure, so a merge
			// done afterwards in PHP could never recover a row the LIMIT had
			// already dropped. Without this the suite cannot tell the two
			// designs apart.
			if ( preg_match( '/LIMIT\s+(\d+)/i', $sql, $lm ) ) {
				$out = array_slice( $out, 0, (int) $lm[1] );
			}
			return $out;
		}
		// GROUP BY day  → per-day series.
		// GROUP BY DATE_SUB(day, INTERVAL WEEKDAY(day) DAY) → per-week series, each bucket keyed
		// to its ISO-Monday floor. Production SELECTs "{$expr} AS day" in both cases, so both
		// return day-keyed rows; the mock mirrors that (a totals fall-through would lack 'day').
		$is_week = stripos( $sql, 'DATE_SUB(day, INTERVAL WEEKDAY(day) DAY)' ) !== false;
		if ( $is_week || stripos( $sql, 'GROUP BY day' ) !== false ) {
			$agg = array();
			foreach ( $rows as $r ) {
				$d = (string) $r['day'];
				if ( $is_week ) {
					$ts  = (int) strtotime( $d . ' 00:00:00 UTC' );
					$dow = (int) gmdate( 'N', $ts ); // 1=Mon … 7=Sun
					$d   = gmdate( 'Y-m-d', $ts - ( $dow - 1 ) * 86400 ); // floor to ISO Monday
				}
				if ( ! isset( $agg[ $d ] ) ) { $agg[ $d ] = array( 'day' => $d, 'views' => 0, 'visits' => 0 ); }
				$agg[ $d ]['views']  += (int) $r['views'];
				$agg[ $d ]['visits'] += (int) $r['visits'];
			}
			ksort( $agg );
			return array_values( $agg );
		}
		// Extended range-totals SELECT (Phase A Task 4) → single aggregate row.
		// Models the wpdb TRANSPORT, not a convenient shape: every value comes
		// back as a STRING (wpdb does not use native int/float results) and a
		// SQL NULL comes back as PHP null. SQL semantics modeled: SUM() skips
		// NULLs and is NULL over zero non-null inputs; COUNT(col) counts
		// non-null only; COUNT(*) counts rows.
		if ( stripos( $sql, 'row_count' ) !== false ) {
			$sum = function ( $key ) use ( $rows ) {
				$acc = null;
				foreach ( $rows as $r ) {
					$val = array_key_exists( $key, $r ) ? $r[ $key ] : null;
					if ( null !== $val ) { $acc = ( null === $acc ? 0 : $acc ) + (float) $val; }
				}
				return $acc;
			};
			$cnt = function ( $key ) use ( $rows ) {
				$c = 0;
				foreach ( $rows as $r ) { if ( null !== ( array_key_exists( $key, $r ) ? $r[ $key ] : null ) ) { ++$c; } }
				return $c;
			};
			$str = function ( $val ) { return null === $val ? null : (string) $val; };
			$v = $sum( 'views' );
			$sw = 0.0; $tw = 0.0;
			foreach ( $rows as $r ) { $sw += (float) ( $r['scroll_avg'] ?? 0 ) * (int) ( $r['views'] ?? 0 ); $tw += (float) ( $r['time_avg'] ?? 0 ) * (int) ( $r['views'] ?? 0 ); }
			return array( array(
				'views'           => $str( $v ),
				'visits'          => $str( $sum( 'visits' ) ),
				'scroll_avg'      => ( null !== $v && $v > 0 ) ? (string) ( $sw / $v ) : null,
				'time_avg'        => ( null !== $v && $v > 0 ) ? (string) ( $tw / $v ) : null,
				'scroll_sum'      => $str( $sum( 'scroll_sum' ) ),
				'scroll_events'   => $str( $sum( 'scroll_events' ) ),
				'time_sum'        => $str( $sum( 'time_sum' ) ),
				'time_events'     => $str( $sum( 'time_events' ) ),
				'pageview_visits' => $str( $sum( 'pageview_visits' ) ),
				'row_count'       => (string) count( $rows ),
				'exact_rows'      => (string) $cnt( 'scroll_sum' ),
				'gated_rows'      => (string) $cnt( 'pageview_visits' ),
			) );
		}
		// No GROUP BY → range totals (single aggregate row).
		$v = 0; $vi = 0; $sw = 0.0; $tw = 0.0;
		foreach ( $rows as $r ) { $v += (int) $r['views']; $vi += (int) $r['visits']; $sw += (float) $r['scroll_avg'] * (int) $r['views']; $tw += (float) $r['time_avg'] * (int) $r['views']; }
		return array( array( 'views' => $v, 'visits' => $vi, 'scroll_avg' => $v ? $sw / $v : 0, 'time_avg' => $v ? $tw / $v : 0 ) );
	}
}
$GLOBALS['wpdb'] = new RD_Stub_wpdb();

require_once __DIR__ . '/../inc/analytics-read.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$fixture = array(
	array( 'day' => '2026-06-10', 'path' => '/a', 'class' => 'human', 'views' => 100, 'visits' => 40, 'scroll_avg' => 60, 'time_avg' => 120 ),
	array( 'day' => '2026-06-11', 'path' => '/a', 'class' => 'human', 'views' => 300, 'visits' => 50, 'scroll_avg' => 80, 'time_avg' => 240 ),
	array( 'day' => '2026-06-11', 'path' => '/b', 'class' => 'human', 'views' => 300, 'visits' => 90, 'scroll_avg' => 50, 'time_avg' => 60 ),
	array( 'day' => '2026-06-11', 'path' => '/a', 'class' => 'bot',   'views' => 999, 'visits' => 1,  'scroll_avg' => 0,  'time_avg' => 0 ),
);

echo "Analytics read accessors\n\n";

echo "Group: top_paths\n";
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = $fixture;
$tp = sn_analytics_top_paths( '2026-06-01', '2026-06-12' );
ok( count( $tp ) === 2, 'top_paths: human paths only, grouped' );
ok( $tp[0]['path'] === '/a' && $tp[0]['views'] === 400, 'top_paths: ordered by views desc (/a=400 > /b=300)' );
$a = array_values( array_filter( $tp, function ( $r ) { return $r['path'] === '/a'; } ) )[0];
ok( $a['views'] === 400, 'top_paths: sums views across days' );
ok( abs( $a['scroll_avg'] - 75.0 ) < 0.01, 'top_paths: scroll_avg views-weighted ((60*100+80*300)/400=75, not plain-avg 70)' );
ok( abs( $a['time_avg'] - 210.0 ) < 0.01, 'top_paths: time_avg views-weighted ((120*100+240*300)/400=210, not plain-avg 180)' );
ok( is_float( $a['scroll_avg'] ) && is_int( $a['views'] ), 'top_paths: types normalized' );
$sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $sql, 'GROUP BY CASE' ) !== false && strpos( $sql, 'ORDER BY views DESC' ) !== false, 'top_paths: SQL groups by the canonical path, orders by views' );
// SQL-shape pins: a plain AVG() regression must not slip through green.
ok(
	strpos( $sql, 'scroll_avg * views' ) !== false && strpos( $sql, 'NULLIF(SUM(views)' ) !== false,
	'top_paths: SQL uses views-weighted scroll expression (not AVG)'
);
ok(
	strpos( $sql, 'time_avg' ) !== false && strpos( $sql, '* views' ) !== false,
	'top_paths: SQL uses views-weighted time expression (not AVG)'
);
// SUM(col) AS alias mapping: a SUM(visits) AS views swap must fail.
ok(
	preg_match( '/SUM\(\s*views\s*\)\s+AS\s+views/i', $sql ) === 1,
	'top_paths: SUM(views) AS views — alias mapping correct'
);
ok(
	preg_match( '/SUM\(\s*visits\s*\)\s+AS\s+visits/i', $sql ) === 1,
	'top_paths: SUM(visits) AS visits — alias mapping correct'
);

echo "\nGroup: top_paths — one page is ONE row (v11.32.0)\n";
// The owner's Top pages panel listed `/notes` and `/notes/` as two pages, 27
// views each. Nothing in ingestion normalises a trailing slash — the daily
// table's primary key is (day, path, class), so the two spellings are two
// stored rows and every read that GROUPs BY the raw column reports them as two
// pages.
//
// The fix has to live in the GROUP BY, not in PHP afterwards. This fixture is
// built to prove exactly that: split, `/about` (20) outranks `/notes` (15) and
// `/notes/` (12) individually, so at LIMIT 2 the second spelling is TRUNCATED
// BY THE DATABASE and a later PHP merge has nothing left to merge. Merged,
// /notes is 27 and leads. Same trap as the freshness clock: you cannot
// post-filter a row the WHERE/LIMIT already excluded.
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'day' => '2026-08-18', 'path' => '/notes',  'class' => 'human', 'views' => 15, 'visits' => 10, 'scroll_avg' => 40, 'time_avg' => 100 ),
	array( 'day' => '2026-08-18', 'path' => '/notes/', 'class' => 'human', 'views' => 12, 'visits' => 8,  'scroll_avg' => 90, 'time_avg' => 300 ),
	array( 'day' => '2026-08-18', 'path' => '/about',  'class' => 'human', 'views' => 20, 'visits' => 15, 'scroll_avg' => 50, 'time_avg' => 200 ),
	array( 'day' => '2026-08-18', 'path' => '/x',      'class' => 'human', 'views' => 5,  'visits' => 4,  'scroll_avg' => 10, 'time_avg' => 10 ),
);
$slash = sn_analytics_top_paths( '2026-08-01', '2026-08-19', 'human', 2 );
ok( count( $slash ) === 2, 'top_paths: the LIMIT is honoured' );
ok( $slash[0]['path'] === '/notes', 'A TRAILING SLASH IS THE SAME PAGE — merged, /notes leads; split, it never reaches the top 2 at all' );
ok( $slash[0]['views'] === 27, 'top_paths: the merged page carries the SUM of both spellings (15 + 12)' );
ok( count( array_filter( $slash, function ( $r ) { return $r['path'] === '/notes/'; } ) ) === 0, 'top_paths: the slashed spelling never appears as its own page' );
// Views-weighted averages must weight across the MERGED group, not one spelling.
ok( abs( $slash[0]['scroll_avg'] - ( ( 40 * 15 + 90 * 12 ) / 27 ) ) < 0.01, 'top_paths: scroll_avg is views-weighted ACROSS the merged spellings' );
// The mock reads the grouping out of the SQL, so this pin is what stops the
// mock from being taught a belief the query does not hold.
$ssql = end( $GLOBALS['wpdb']->queries );
ok( stripos( $ssql, "GROUP BY CASE" ) !== false && stripos( $ssql, "TRIM(TRAILING '/'" ) !== false, 'top_paths: the SQL groups by the CANONICAL path, not the raw column' );
ok( preg_match( '/GROUP BY\s+path\b/i', $ssql ) !== 1, 'top_paths: the bare column is no longer the group key' );

// The root path is not a trailing slash to strip — it would collapse to the
// empty string and stop being a page at all.
ok( sn_analytics_canonical_path( '/' ) === '/', 'canonical: the ROOT stays "/" — never trimmed to nothing' );
ok( sn_analytics_canonical_path( '/notes/' ) === '/notes', 'canonical: one trailing slash is dropped' );
ok( sn_analytics_canonical_path( '/notes//' ) === '/notes', 'canonical: repeated trailing slashes are dropped' );
ok( sn_analytics_canonical_path( '/notes' ) === '/notes', 'canonical: an already-canonical path is unchanged' );
ok( sn_analytics_canonical_path( '' ) === '', 'canonical: an empty path is NOT invented into a root — ingestion refuses it, so neither do we' );

echo "\nGroup: range_totals\n";
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = $fixture;
$rt = sn_analytics_range_totals( '2026-06-01', '2026-06-12' );
ok( $rt['views'] === 700 && $rt['visits'] === 180, 'range_totals: sums human views/visits (excludes bot)' );
ok( abs( $rt['scroll_avg'] - 64.2857 ) < 0.01, 'range_totals: scroll_avg views-weighted ((60*100+80*300+50*300)/700≈64.29)' );
ok( is_int( $rt['views'] ) && is_float( $rt['scroll_avg'] ), 'range_totals: types normalized' );
$sql = end( $GLOBALS['wpdb']->queries );
// SQL-shape pins for range_totals (previously had NO SQL assertion).
ok(
	strpos( $sql, 'scroll_avg * views' ) !== false && strpos( $sql, 'NULLIF(SUM(views)' ) !== false,
	'range_totals: SQL uses views-weighted scroll expression (not AVG)'
);
ok(
	strpos( $sql, 'time_avg' ) !== false && strpos( $sql, '* views' ) !== false,
	'range_totals: SQL uses views-weighted time expression (not AVG)'
);
ok(
	preg_match( '/SUM\(\s*views\s*\)\s+AS\s+views/i', $sql ) === 1,
	'range_totals: SUM(views) AS views — alias mapping correct'
);
ok(
	preg_match( '/SUM\(\s*visits\s*\)\s+AS\s+visits/i', $sql ) === 1,
	'range_totals: SUM(visits) AS visits — alias mapping correct'
);

echo "\nGroup: daily_series\n";
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = $fixture;
$ds = sn_analytics_daily_series( '2026-06-01', '2026-06-12' );
ok( count( $ds ) === 2, 'daily_series: one row per day' );
ok( $ds[0]['day'] === '2026-06-10' && $ds[1]['day'] === '2026-06-11', 'daily_series: ascending by day' );
ok( $ds[1]['views'] === 600, 'daily_series: 2026-06-11 human views = 300(/a)+300(/b)' );
$sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $sql, 'GROUP BY day' ) !== false && strpos( $sql, 'ORDER BY day ASC' ) !== false, 'daily_series: SQL groups by day ascending' );
ok(
	preg_match( '/SUM\(\s*views\s*\)\s+AS\s+views/i', $sql ) === 1,
	'daily_series: SUM(views) AS views — alias mapping correct'
);
ok(
	preg_match( '/SUM\(\s*visits\s*\)\s+AS\s+visits/i', $sql ) === 1,
	'daily_series: SUM(visits) AS visits — alias mapping correct'
);

echo "\nGroup: daily_series weekly granularity\n";
// Audit INFO-1: production SELECTs "{$expr} AS day", so week rows ARE day-keyed. The mock now
// emulates the week-floor GROUP BY (floors each row to its ISO-Monday bucket) instead of falling
// through to the no-GROUP-BY totals row — which lacked a 'day' key, so sn_analytics_daily_series()
// built '' from the undefined source and tripped an "Undefined array key \"day\"" notice.
$GLOBALS['__warnings'] = array();
set_error_handler( function ( $errno, $errstr ) {
	$GLOBALS['__warnings'][] = $errstr;
	return true; // swallow: assert on the captured set, not on stderr
}, E_WARNING | E_NOTICE );
$ws  = sn_analytics_daily_series( '2026-03-01', '2026-06-12', 'human', 'week' );
restore_error_handler();
$sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $sql, 'DATE_SUB(day, INTERVAL WEEKDAY(day) DAY)' ) !== false, 'weekly: SQL floors day to ISO Monday' );
ok( strpos( $sql, 'GROUP BY DATE_SUB(day, INTERVAL WEEKDAY(day) DAY)' ) !== false, 'weekly: groups by the week-floor expression' );
ok( count( array_filter( $GLOBALS['__warnings'], function ( $w ) { return stripos( $w, 'Undefined array key' ) !== false; } ) ) === 0,
    'weekly: no "Undefined array key" warning during the week aggregation' );
ok( ! empty( $ws ) && ! empty( $ws[0]['day'] ) && (int) gmdate( 'N', (int) strtotime( $ws[0]['day'] . ' 00:00:00 UTC' ) ) === 1,
    'weekly: each bucket day floors to an ISO Monday' );
// $refresh=true: this exact [from,to,class,granularity] tuple was already
// primed by the daily_series group above (line 154) — the request-scope memo
// added below would otherwise serve that cached result here instead of
// issuing a fresh query, and $sql2 would stay pinned to the WEEKLY query
// just above instead of reflecting this call.
sn_analytics_daily_series( '2026-06-01', '2026-06-12', 'human', 'day', true );
$sql2 = end( $GLOBALS['wpdb']->queries );
ok( strpos( $sql2, 'GROUP BY day' ) !== false && strpos( $sql2, 'DATE_SUB' ) === false, 'day granularity: unchanged GROUP BY day' );

echo "\nGroup: range_totals request-scope memo (D5 perf)\n";
// Distinct window from every earlier group so this memo group can't collide
// with (or be poisoned by) the range_totals key already primed above.
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = $fixture;
$reads_before = count( $GLOBALS['wpdb']->queries );
$m1 = sn_analytics_range_totals( '2026-07-01', '2026-07-07', 'human' );
$m2 = sn_analytics_range_totals( '2026-07-01', '2026-07-07', 'human' );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 1, 'range_totals: identical repeat call issues exactly one underlying read' );
ok( $m1 === $m2, 'range_totals: memoized calls return the identical cached result' );

$m3 = sn_analytics_range_totals( '2026-07-01', '2026-07-07', 'bot' );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 2, 'range_totals: a different class is a distinct memo key (second read)' );

$m4 = sn_analytics_range_totals( '2026-07-01', '2026-07-07', 'human', true );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 3, 'range_totals: $refresh=true re-primes the memo (third read)' );
ok( $m4 === $m1, 'range_totals: refreshed read still shapes an identical result over unchanged fixture data' );

echo "\nGroup: daily_series request-scope memo (D5 perf)\n";
// Distinct window from every earlier group (including the daily_series
// groups above) so this memo group can't collide with a key already
// primed elsewhere in this file.
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = $fixture;
$reads_before = count( $GLOBALS['wpdb']->queries );
$d1 = sn_analytics_daily_series( '2026-08-01', '2026-08-07', 'human', 'day' );
$d2 = sn_analytics_daily_series( '2026-08-01', '2026-08-07', 'human', 'day' );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 1, 'daily_series: identical repeat call issues exactly one underlying read' );
ok( $d1 === $d2, 'daily_series: memoized calls return the identical cached result' );

$d3 = sn_analytics_daily_series( '2026-08-01', '2026-08-07', 'bot', 'day' );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 2, 'daily_series: a different class is a distinct memo key (second read)' );

$d4 = sn_analytics_daily_series( '2026-08-01', '2026-08-07', 'human', 'week' );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 3, 'daily_series: a different granularity is a distinct memo key (third read)' );

$d5 = sn_analytics_daily_series( '2026-08-01', '2026-08-07', 'human', 'day', true );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 4, 'daily_series: $refresh=true re-primes the memo (fourth read)' );
ok( $d5 === $d1, 'daily_series: refreshed read still shapes an identical result over unchanged fixture data' );

// ── Phase A Task 4: derived metrics merged into the range totals ─────────────
// Distinct windows per group: sn_analytics_range_totals() memoizes per
// [from,to,class], so window reuse would serve a stale fixture.

echo "\nGroup: range_totals derived metrics — all-modern range (Phase A)\n";
$GLOBALS['__rd_options']['sn_analytics_exact_metrics_since'] = '2026-04-18';
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'day' => '2026-09-01', 'path' => '/a', 'class' => 'human', 'views' => 100, 'visits' => 50, 'scroll_avg' => 60, 'time_avg' => 120, 'scroll_sum' => 6000.0, 'scroll_events' => 90,  'time_sum' => 12000.0, 'time_events' => 80,  'pageview_visits' => 45 ),
	array( 'day' => '2026-09-02', 'path' => '/a', 'class' => 'human', 'views' => 200, 'visits' => 70, 'scroll_avg' => 70, 'time_avg' => 100, 'scroll_sum' => 8000.0, 'scroll_events' => 110, 'time_sum' => 20000.0, 'time_events' => 150, 'pageview_visits' => 60 ),
	array( 'day' => '2026-09-03', 'path' => '/b', 'class' => 'human', 'views' => 100, 'visits' => 30, 'scroll_avg' => 50, 'time_avg' => 90,  'scroll_sum' => 2000.0, 'scroll_events' => 40,  'time_sum' => 7000.0,  'time_events' => 70,  'pageview_visits' => 25 ),
	array( 'day' => '2026-09-02', 'path' => '/a', 'class' => 'bot',   'views' => 500, 'visits' => 500, 'scroll_avg' => 0, 'time_avg' => 0,   'scroll_sum' => 1.0,    'scroll_events' => 1,   'time_sum' => 1.0,     'time_events' => 1,   'pageview_visits' => 500 ),
);
$t = sn_analytics_range_totals( '2026-09-01', '2026-09-03', 'human' );
// Kept-deprecated legacy quartet: values and semantics UNALTERED by the merge.
ok( $t['views'] === 400 && $t['visits'] === 150, 'modern: legacy views/visits kept unaltered (400/150, bot excluded)' );
ok( abs( $t['scroll_avg'] - 62.5 ) < 0.01, 'modern: legacy scroll_avg still views-weighted ((60*100+70*200+50*100)/400=62.5)' );
ok( abs( $t['time_avg'] - 102.5 ) < 0.01, 'modern: legacy time_avg still views-weighted ((120*100+100*200+90*100)/400=102.5)' );
// Derived — every value pinned from the RANGE TOTALS (derive runs ONCE on sums).
ok( array_key_exists( 'unique_visitor_days', $t ) && $t['unique_visitor_days'] === 150, 'modern: unique_visitor_days = 150 (int, honest alias of summed visits)' );
ok( array_key_exists( 'pageview_visits', $t ) && $t['pageview_visits'] === 130, 'modern: pageview_visits = 130 (int, 45+60+25 summed across days)' );
ok( array_key_exists( 'viewless_visits', $t ) && $t['viewless_visits'] === 20, 'modern: viewless_visits = 20 (150 − 130)' );
ok( array_key_exists( 'view_visit_ratio', $t ) && is_float( $t['view_visit_ratio'] ) && abs( $t['view_visit_ratio'] - 400 / 130 ) < 1e-9, 'modern: view_visit_ratio = 400/130 ≈ 3.0769' );
ok( array_key_exists( 'pageviews_per_visitor_day', $t ) && is_float( $t['pageviews_per_visitor_day'] ) && abs( $t['pageviews_per_visitor_day'] - 400 / 150 ) < 1e-9, 'modern: pageviews_per_visitor_day = 400/150 ≈ 2.6667' );
ok( array_key_exists( 'scroll_avg_per_view', $t ) && is_float( $t['scroll_avg_per_view'] ) && abs( $t['scroll_avg_per_view'] - 15.0 ) < 1e-9, 'modern: scroll_avg_per_view = 25×240/400 = 15.0 (v9.64.0 depth unit, from summed scroll_events)' );
ok( array_key_exists( 'time_avg_per_view', $t ) && is_float( $t['time_avg_per_view'] ) && abs( $t['time_avg_per_view'] - 97.5 ) < 1e-9, 'modern: time_avg_per_view = 39000/400 = 97.5 (exact)' );
ok( array_key_exists( 'scroll_avg_per_visit', $t ) && is_float( $t['scroll_avg_per_visit'] ) && abs( $t['scroll_avg_per_visit'] - 40.0 ) < 1e-9, 'modern: scroll_avg_per_visit = 25×240/150 = 40.0 (diluted by viewless days)' );
ok( array_key_exists( 'time_avg_per_visit', $t ) && is_float( $t['time_avg_per_visit'] ) && abs( $t['time_avg_per_visit'] - 260.0 ) < 1e-9, 'modern: time_avg_per_visit = 39000/150 = 260.0' );
ok( array_key_exists( 'integrity_violation', $t ) && $t['integrity_violation'] === false, 'modern: integrity_violation === false (views 400 ≥ pageview_visits 130)' );
ok( array_key_exists( 'exact_metrics_since', $t ) && $t['exact_metrics_since'] === '2026-04-18', 'modern: exact_metrics_since carries the option value' );
// The FULL key contract, order pinned — the exact response keys Task 4 adds.
ok( array_keys( $t ) === array(
	'views', 'visits', 'scroll_avg', 'time_avg',
	'unique_visitor_days', 'pageview_visits', 'viewless_visits',
	'view_visit_ratio', 'pageviews_per_visitor_day',
	'scroll_avg_per_view', 'time_avg_per_view',
	'scroll_avg_per_visit', 'time_avg_per_visit',
	'integrity_violation', 'exact_metrics_since',
), 'modern: exact key set + order — legacy quartet first, spec-§4 fields, exact_metrics_since last' );
ok( get_option( 'sn_analytics_integrity_alert' ) === false, 'modern: guard silent on healthy data (no alert option)' );
$sql = end( $GLOBALS['wpdb']->queries );
ok( preg_match( '/SUM\(\s*scroll_sum\s*\)\s+AS\s+scroll_sum/i', $sql ) === 1, 'modern SQL: SUM(scroll_sum) AS scroll_sum' );
ok( preg_match( '/SUM\(\s*pageview_visits\s*\)\s+AS\s+pageview_visits/i', $sql ) === 1, 'modern SQL: SUM(pageview_visits) AS pageview_visits' );
ok( preg_match( '/COUNT\(\s*\*\s*\)\s+AS\s+row_count/i', $sql ) === 1, 'modern SQL: COUNT(*) AS row_count' );
ok( preg_match( '/COUNT\(\s*scroll_sum\s*\)\s+AS\s+exact_rows/i', $sql ) === 1, 'modern SQL: COUNT(scroll_sum) AS exact_rows (non-null = modern rows)' );
ok( preg_match( '/COUNT\(\s*pageview_visits\s*\)\s+AS\s+gated_rows/i', $sql ) === 1, 'modern SQL: COUNT(pageview_visits) AS gated_rows' );

echo "\nGroup: range_totals mixed legacy+modern range (Phase A)\n";
// One legacy (pre-v5, NULL sums) + one modern row. The mixed-range rule: ANY
// NULL scroll_sum row nulls the exact engagement + gated fields for the WHOLE
// range — SQL SUM() would happily skip the NULLs and serve a silently partial
// denominator (3000/150 = 20.0 here); honest null beats that.
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'day' => '2026-10-01', 'path' => '/a', 'class' => 'human', 'views' => 50,  'visits' => 80, 'scroll_avg' => 40, 'time_avg' => 70, 'scroll_sum' => null,   'scroll_events' => null, 'time_sum' => null,    'time_events' => null, 'pageview_visits' => null ),
	array( 'day' => '2026-10-02', 'path' => '/a', 'class' => 'human', 'views' => 100, 'visits' => 20, 'scroll_avg' => 60, 'time_avg' => 90, 'scroll_sum' => 3000.0, 'scroll_events' => 45,   'time_sum' => 9000.0,  'time_events' => 60,   'pageview_visits' => 15 ),
);
$t2 = sn_analytics_range_totals( '2026-10-01', '2026-10-02', 'human' );
ok( $t2['views'] === 150 && $t2['visits'] === 100, 'mixed: legacy views/visits still real sums (150/100)' );
ok( array_key_exists( 'scroll_avg_per_view', $t2 ) && null === $t2['scroll_avg_per_view'], 'mixed: scroll_avg_per_view null — NOT the silently-partial 25×45/150=7.5' );
ok( array_key_exists( 'time_avg_per_view', $t2 ) && null === $t2['time_avg_per_view'], 'mixed: time_avg_per_view null' );
ok( array_key_exists( 'scroll_avg_per_visit', $t2 ) && null === $t2['scroll_avg_per_visit'], 'mixed: scroll_avg_per_visit null' );
ok( array_key_exists( 'time_avg_per_visit', $t2 ) && null === $t2['time_avg_per_visit'], 'mixed: time_avg_per_visit null' );
ok( array_key_exists( 'pageview_visits', $t2 ) && null === $t2['pageview_visits'], 'mixed: pageview_visits null — NOT the partial 15' );
ok( array_key_exists( 'viewless_visits', $t2 ) && null === $t2['viewless_visits'], 'mixed: viewless_visits null (gated unknown)' );
ok( array_key_exists( 'view_visit_ratio', $t2 ) && null === $t2['view_visit_ratio'], 'mixed: view_visit_ratio null (gated unknown)' );
ok( array_key_exists( 'unique_visitor_days', $t2 ) && $t2['unique_visitor_days'] === 100, 'mixed: unique_visitor_days = 100 stays known (visits is NOT NULL legacy)' );
ok( array_key_exists( 'pageviews_per_visitor_day', $t2 ) && is_float( $t2['pageviews_per_visitor_day'] ) && abs( $t2['pageviews_per_visitor_day'] - 1.5 ) < 1e-9, 'mixed: pageviews_per_visitor_day = 150/100 = 1.5 stays exact (both denominators legacy-known)' );
ok( array_key_exists( 'integrity_violation', $t2 ) && $t2['integrity_violation'] === false, 'mixed: integrity_violation false (gated unknown → no verdict)' );
ok( array_key_exists( 'exact_metrics_since', $t2 ) && $t2['exact_metrics_since'] === '2026-04-18', 'mixed: exact_metrics_since present — says WHY the exact fields are null' );

echo "\nGroup: range_totals gated-partial range (Phase A)\n";
// Engagement sums known on EVERY row, but one row's pageview_visits is NULL
// (a gated-query-failed day). Engagement stays exact; the gated family nulls —
// the partial SUM(pageview_visits)=25 would fake view_visit_ratio 100/25=4.0.
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'day' => '2026-11-01', 'path' => '/a', 'class' => 'human', 'views' => 60, 'visits' => 30, 'scroll_avg' => 50, 'time_avg' => 80, 'scroll_sum' => 1200.0, 'scroll_events' => 20, 'time_sum' => 4800.0, 'time_events' => 40, 'pageview_visits' => 25 ),
	array( 'day' => '2026-11-02', 'path' => '/a', 'class' => 'human', 'views' => 40, 'visits' => 20, 'scroll_avg' => 55, 'time_avg' => 85, 'scroll_sum' => 800.0,  'scroll_events' => 10, 'time_sum' => 3200.0, 'time_events' => 30, 'pageview_visits' => null ),
);
$t3 = sn_analytics_range_totals( '2026-11-01', '2026-11-02', 'human' );
ok( array_key_exists( 'scroll_avg_per_view', $t3 ) && is_float( $t3['scroll_avg_per_view'] ) && abs( $t3['scroll_avg_per_view'] - 7.5 ) < 1e-9, 'gated-partial: scroll_avg_per_view = 25×30/100 = 7.5 (exact engagement survives)' );
ok( array_key_exists( 'time_avg_per_view', $t3 ) && is_float( $t3['time_avg_per_view'] ) && abs( $t3['time_avg_per_view'] - 80.0 ) < 1e-9, 'gated-partial: time_avg_per_view = 8000/100 = 80.0' );
ok( array_key_exists( 'pageview_visits', $t3 ) && null === $t3['pageview_visits'], 'gated-partial: pageview_visits null — NOT the partial 25' );
ok( array_key_exists( 'viewless_visits', $t3 ) && null === $t3['viewless_visits'], 'gated-partial: viewless_visits null' );
ok( array_key_exists( 'view_visit_ratio', $t3 ) && null === $t3['view_visit_ratio'], 'gated-partial: view_visit_ratio null — NOT the fake 100/25=4.0' );
ok( array_key_exists( 'integrity_violation', $t3 ) && $t3['integrity_violation'] === false, 'gated-partial: integrity_violation false (gated unknown)' );

echo "\nGroup: range_totals zero-traffic range (Phase A)\n";
// An EMPTY range is an ANSWER (zero traffic): every count is a REAL 0, every
// ratio is null (÷0) — never invent a rate from nothing, never null a real 0.
unset( $GLOBALS['__rd_options']['sn_analytics_exact_metrics_since'] );
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array();
$t4 = sn_analytics_range_totals( '2026-12-01', '2026-12-07', 'human' );
ok( $t4['views'] === 0 && $t4['visits'] === 0 && $t4['scroll_avg'] === 0.0 && $t4['time_avg'] === 0.0, 'zero: legacy quartet reads real 0s' );
ok( array_key_exists( 'unique_visitor_days', $t4 ) && $t4['unique_visitor_days'] === 0, 'zero: unique_visitor_days = 0 (real 0, not null)' );
ok( array_key_exists( 'pageview_visits', $t4 ) && $t4['pageview_visits'] === 0, 'zero: pageview_visits = 0 (real 0, not null)' );
ok( array_key_exists( 'viewless_visits', $t4 ) && $t4['viewless_visits'] === 0, 'zero: viewless_visits = 0' );
ok( array_key_exists( 'view_visit_ratio', $t4 ) && null === $t4['view_visit_ratio'], 'zero: view_visit_ratio null (÷0 — never a fabricated rate)' );
ok( array_key_exists( 'pageviews_per_visitor_day', $t4 ) && null === $t4['pageviews_per_visitor_day'], 'zero: pageviews_per_visitor_day null' );
ok( array_key_exists( 'scroll_avg_per_view', $t4 ) && null === $t4['scroll_avg_per_view'], 'zero: scroll_avg_per_view null' );
ok( array_key_exists( 'time_avg_per_view', $t4 ) && null === $t4['time_avg_per_view'], 'zero: time_avg_per_view null' );
ok( array_key_exists( 'scroll_avg_per_visit', $t4 ) && null === $t4['scroll_avg_per_visit'], 'zero: scroll_avg_per_visit null' );
ok( array_key_exists( 'time_avg_per_visit', $t4 ) && null === $t4['time_avg_per_visit'], 'zero: time_avg_per_visit null' );
ok( array_key_exists( 'integrity_violation', $t4 ) && $t4['integrity_violation'] === false, 'zero: integrity_violation false' );
ok( array_key_exists( 'exact_metrics_since', $t4 ) && null === $t4['exact_metrics_since'], 'zero: exact_metrics_since null while the option is unset' );

echo "\nGroup: read-side integrity guard (Phase A)\n";
// Inverted human range (views < pageview_visits — arithmetically impossible,
// so a genuine upstream bug): the guard mirrors the rollup's — error_log +
// the SAME sn_analytics_integrity_alert option — and the values are served
// UN-clamped. Idempotent: the same violation must not churn the option.
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'day' => '2027-01-01', 'path' => '/a', 'class' => 'human', 'views' => 2, 'visits' => 9, 'scroll_avg' => 10, 'time_avg' => 10, 'scroll_sum' => 20.0, 'scroll_events' => 2, 'time_sum' => 40.0, 'time_events' => 2, 'pageview_visits' => 5 ),
);
$guard_log     = tempnam( sys_get_temp_dir(), 'sn-rd-guard' );
$old_error_log = ini_set( 'error_log', $guard_log );
$writes_before = $GLOBALS['__rd_option_writes'];
$tg            = sn_analytics_range_totals( '2027-01-01', '2027-01-01', 'human' );
ok( $tg['integrity_violation'] === true, 'guard: integrity_violation === true on the inverted range' );
ok( $tg['views'] === 2 && $tg['pageview_visits'] === 5, 'guard: values served UN-clamped (2 < 5 still visible)' );
$alert = get_option( 'sn_analytics_integrity_alert' );
ok( is_array( $alert ) && ( $alert['views'] ?? null ) === 2 && ( $alert['pageview_visits'] ?? null ) === 5
	&& ( $alert['from'] ?? null ) === '2027-01-01' && ( $alert['to'] ?? null ) === '2027-01-01'
	&& ( $alert['scope'] ?? null ) === 'read-range',
	'guard: sn_analytics_integrity_alert holds the read-range violation payload' );
ok( $GLOBALS['__rd_option_writes'] === $writes_before + 1, 'guard: exactly one option write for the violation' );
// Same violation again ($refresh bypasses the memo): NO second write.
sn_analytics_range_totals( '2027-01-01', '2027-01-01', 'human', true );
ok( $GLOBALS['__rd_option_writes'] === $writes_before + 1, 'guard: idempotent — re-reading the same violation does not churn the option' );
// Non-human class: derive still reports honestly, but the alert is human-only
// (mirrors the rollup guard's class gate).
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'day' => '2027-01-02', 'path' => '/x', 'class' => 'bot', 'views' => 1, 'visits' => 9, 'scroll_avg' => 0, 'time_avg' => 0, 'scroll_sum' => 0.0, 'scroll_events' => 0, 'time_sum' => 0.0, 'time_events' => 0, 'pageview_visits' => 7 ),
);
$tb = sn_analytics_range_totals( '2027-01-02', '2027-01-02', 'bot' );
ini_set( 'error_log', (string) $old_error_log );
ok( $tb['integrity_violation'] === true, 'guard: bot inversion still REPORTED honestly in the response' );
ok( $GLOBALS['__rd_option_writes'] === $writes_before + 1, 'guard: bot inversion does NOT write the alert (human-only side effect)' );
$logged = (string) @file_get_contents( $guard_log );
ok( strpos( $logged, '[sn-analytics] integrity violation' ) !== false && strpos( $logged, '2027-01-01' ) !== false,
	'guard: error_log records the violation with the offending range' );
@unlink( $guard_log );

echo "\nGroup: range_totals failed wpdb read (adversarial finding 2)\n";
// A FAILED $wpdb read (last_error set) yields an empty result set — which the
// zero-rows branch would otherwise read as a real zero-traffic ANSWER and
// fabricate measured zeros (pageview_visits => 0, scroll_sum => 0.0) on the
// new honest-vocabulary fields. Transport failure is NOT an answer: the new
// fields must all be null, the guard must not fire, and the legacy quartet
// keeps its long-standing pre-existing zero shape (back-compat, unchanged).
unset( $GLOBALS['__rd_options']['sn_analytics_integrity_alert'] );
$GLOBALS['__rd_options']['sn_analytics_exact_metrics_since'] = '2026-04-19';
// Fixture rows that WOULD produce non-zero totals — proving the nulls come
// from the failure gate, not from an empty fixture.
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'day' => '2027-02-01', 'path' => '/a', 'class' => 'human', 'views' => 100, 'visits' => 50, 'scroll_avg' => 60, 'time_avg' => 120, 'scroll_sum' => 6000.0, 'scroll_events' => 90, 'time_sum' => 12000.0, 'time_events' => 80, 'pageview_visits' => 45 ),
);
$GLOBALS['wpdb']->last_error = "Table 'wp.wp_sn_analytics_daily' doesn't exist";
$fail_log      = tempnam( sys_get_temp_dir(), 'sn-rd-fail' );
$old_fail_log  = ini_set( 'error_log', $fail_log );
$writes_before = $GLOBALS['__rd_option_writes'];
$tf            = sn_analytics_range_totals( '2027-02-01', '2027-02-07', 'human' );
ini_set( 'error_log', (string) $old_fail_log );
ok( $tf['views'] === 0 && $tf['visits'] === 0 && $tf['scroll_avg'] === 0.0 && $tf['time_avg'] === 0.0,
	'failed-read: legacy quartet keeps its pre-existing zero shape (back-compat unchanged)' );
foreach ( array(
	'unique_visitor_days', 'pageview_visits', 'viewless_visits',
	'view_visit_ratio', 'pageviews_per_visitor_day',
	'scroll_avg_per_view', 'time_avg_per_view',
	'scroll_avg_per_visit', 'time_avg_per_visit',
) as $rd_k ) {
	ok( array_key_exists( $rd_k, $tf ) && null === $tf[ $rd_k ],
		"failed-read: $rd_k is null — a transport failure is NOT an answer, never a fabricated measured 0" );
}
ok( $tf['integrity_violation'] === false, 'failed-read: integrity_violation false (no verdict without inputs)' );
ok( $GLOBALS['__rd_option_writes'] === $writes_before, 'failed-read: no option write — the read-side guard is skipped' );
ok( get_option( 'sn_analytics_integrity_alert' ) === false, 'failed-read: no alert option appears' );
ok( $tf['exact_metrics_since'] === '2026-04-19', 'failed-read: exact_metrics_since still serves the option (independent of the wpdb read)' );
ok( array_keys( $tf ) === array(
	'views', 'visits', 'scroll_avg', 'time_avg',
	'unique_visitor_days', 'pageview_visits', 'viewless_visits',
	'view_visit_ratio', 'pageviews_per_visitor_day',
	'scroll_avg_per_view', 'time_avg_per_view',
	'scroll_avg_per_visit', 'time_avg_per_visit',
	'integrity_violation', 'exact_metrics_since',
), 'failed-read: full key contract unchanged (nulls, never missing keys)' );
$fail_logged = (string) @file_get_contents( $fail_log );
ok( strpos( $fail_logged, '[sn-analytics]' ) !== false && strpos( $fail_logged, '2027-02-01' ) !== false
	&& strpos( $fail_logged, "doesn't exist" ) !== false,
	'failed-read: error_log records the failed read with the range and the wpdb error' );
@unlink( $fail_log );

// The mirror image must NOT regress: last_error EMPTY with genuinely 0 rows is
// an ANSWER (zero traffic) — real 0 counts survive, never nulled.
$GLOBALS['wpdb']->last_error = '';
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array();
$te = sn_analytics_range_totals( '2027-03-01', '2027-03-07', 'human' );
ok( $te['unique_visitor_days'] === 0 && $te['pageview_visits'] === 0 && $te['viewless_visits'] === 0,
	'empty-not-failed: real 0 counts survive (empty is an ANSWER — the zero-rows branch is untouched)' );
ok( null === $te['view_visit_ratio'] && null === $te['scroll_avg_per_view'],
	'empty-not-failed: ratios stay null over ÷0 exactly as before' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
