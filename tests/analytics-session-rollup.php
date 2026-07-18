<?php
/**
 * Unit test for the session-rollup row normalizer. Run: php tests/analytics-session-rollup.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
// The rollup module registers hooks at load; stub them to no-ops.
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
require __DIR__ . '/../inc/analytics-session-rollup.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── wpdb stub ────────────────────────────────────────────────────────────────
// Placeholder-type-faithful like real $wpdb->prepare(): %d→int, %f→float cast,
// %s→quoted string. Lets the upsert's generated SQL be asserted directly, and a
// future %s→%f regression on a float column becomes observable (unquoted value).
class SR_Stub_wpdb {
	public $prefix  = 'wp_';
	public $queries = array();
	// Canned get_results() payload. REAL wpdb transports every column of an
	// ARRAY_A row as a STRING ("12", "42.50", "2026-06-01") regardless of the
	// column type — the read-accessor tests below MUST feed string values so
	// they exercise the accessor's deliberate re-typing, not a convenient
	// pre-typed shape the transport never produces.
	public $results = array();
	// Failure shape, modeled like REAL wpdb: a FAILED query (missing/corrupt
	// table) sets ->last_error non-empty and get_results() returns array() —
	// NOT null. null comes back only when the query string itself was falsy
	// (prepare() failed). Set $fail_with to simulate a failed query; a stub
	// returning null on failure would fabricate a transform the real callee
	// never produces (test-stub drift).
	public $last_error = '';
	public $fail_with  = '';
	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? '';
			++$i;
			switch ( $m[0] ) {
				case '%d': return (string) (int) $a;
				case '%f': return (string) (float) $a;
				default:   return "'" . addslashes( (string) $a ) . "'";
			}
		}, $query );
	}
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
	public function get_results( $sql, $output = OBJECT ) {
		$this->queries[]  = $sql;
		$this->last_error = $this->fail_with; // real wpdb resets last_error per query, then sets it on failure.
		if ( '' !== $this->fail_with ) {
			return array(); // the REAL failed-query shape: [], with last_error set.
		}
		return $this->results;
	}
}
if ( ! defined( 'OBJECT' ) )  { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
$GLOBALS['wpdb'] = new SR_Stub_wpdb();

echo "\nGroup: sn_session_rollup_normalize\n";
$rows = array(
	array( 'day' => '2026-06-01', 'class' => 'human', 'visits' => '12.0', 'bounce_pct' => '40', 'ppv' => '1.8', 'median_dur' => '55' ),
	array( 'day' => 'bad-date',   'class' => 'human', 'visits' => 5 ),          // dropped
	array( 'day' => '2026-06-01', 'class' => 'martian', 'visits' => 3 ),        // dropped (class)
);
$clean = sn_session_rollup_normalize( $rows );
ok( 1 === count( $clean ), 'only the valid row survives' );
ok( 12 === $clean[0]['visits'], 'visits coerced to int' );
ok( '2026-06-01' === $clean[0]['day'] && 'human' === $clean[0]['class'], 'day/class preserved' );

// ── Locale-safe float binding (regression) ────────────────────────────────────
// $wpdb->prepare() routes %f through vsprintf() (LC_NUMERIC-sensitive): under a
// comma-decimal server locale (de_DE, pt_BR, …) a raw-float %f renders 42.5 as
// "42,5" — corrupt SQL. bounce_pct/ppv must bind as '.'-decimal strings
// (number_format → %s), so the generated SQL is locale-independent.
echo "\nGroup: locale-safe float binding (upsert)\n";
$__saved_numeric = setlocale( LC_NUMERIC, '0' ); // query current, for restore
setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'de_DE.ISO8859-1' ); // no-op if uninstalled
$GLOBALS['wpdb'] = new SR_Stub_wpdb();
sn_session_rollup_upsert( array(
	array( 'day' => '2026-06-01', 'class' => 'human', 'visits' => 12, 'bounce_pct' => 42.5, 'ppv' => 1.75, 'median_dur' => 55 ),
) );
$q = $GLOBALS['wpdb']->queries[0];
ok( strpos( $q, "'42.50'" ) !== false, 'bounce_pct bound as a dot-decimal 2dp string (%s), not %f' );
ok( strpos( $q, "'1.75'" ) !== false, 'ppv bound as a dot-decimal 2dp string (%s), not %f' );
ok( strpos( $q, '42,5' ) === false && strpos( $q, '1,75' ) === false, 'no comma decimal under a de_DE LC_NUMERIC' );
// Column order preserved: day, class, visits, bounce_pct, ppv, median_dur.
ok( strpos( $q, "'2026-06-01', 'human', 12, '42.50', '1.75', 55" ) !== false, 'binds columns in exact order' );
if ( false !== $__saved_numeric ) { setlocale( LC_NUMERIC, $__saved_numeric ); }

// ── Visit-quality is computed over PAGEVIEW-FILTERED visits (matches the live view) ──
// Bug: sn_session_rollup_run() fed the RAW fetched summaries straight into
// sn_session_metrics(), skipping the sn_pageview_visits() filter the interactive
// Visits view applies first. Pageview-less groups (RSS srv:1 'ce' polls, orphan
// scroll/timing beacons) then inflate visits and wreck bounce / ppv / median in the
// durable wp_sn_session_daily table, so the trend line permanently disagrees with
// the live view for the same window. The rollup must filter exactly like the view.
echo "\nGroup: run() filters pageview-less groups before metrics\n";
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
// analytics-sessions.php is NOT loaded here, so these are safe stubs (no redeclare).
$GLOBALS['__sr_metrics_pv'] = array();
function sn_analytics_config() { return array( 'account_id' => 'a', 'token' => 't' ); }
function sn_analytics_fetch_session_events( $from, $to, $class ) {
	// Global-driven so later groups can vary the fetched day. Default: two real
	// pageview visits + one pageview-less server/RSS group (the phantom).
	return $GLOBALS['__sr_fetch'] ?? array( 'configured' => true, 'summaries' => array(
		array( 'pageviews' => 2, 'duration' => 40, 'engaged' => 1 ),
		array( 'pageviews' => 1, 'duration' => 5,  'engaged' => 0 ),
		array( 'pageviews' => 0, 'duration' => 0,  'engaged' => 0 ), // RSS 'ce' poll — NOT a visit
	) );
}
// v9.66.0 exit-bridge spy: records every pageroles upsert the run issues. The
// real callee (inc/analytics-pageroles.php) is NOT loaded here, so the run's
// function_exists guard sees THIS spy and the wiring becomes drivable.
$GLOBALS['__sr_exit_upserts'] = array();
function sn_analytics_pageroles_upsert( $rows ) {
	$GLOBALS['__sr_exit_upserts'][] = $rows;
	return count( (array) $rows );
}
// Real filter semantics (mirrors inc/analytics-sessions.php::sn_pageview_visits).
function sn_pageview_visits( array $summaries ) {
	return array_values( array_filter( $summaries, function ( $s ) {
		return (int) ( $s['pageviews'] ?? 0 ) >= 1;
	} ) );
}
// Spy: record how many summaries the metric aggregator actually received.
function sn_session_metrics( array $summaries ) {
	$GLOBALS['__sr_metrics_pv'][] = count( $summaries );
	return array( 'visits' => count( $summaries ), 'bounce_rate' => 0.0, 'pages_per_visit' => 0.0, 'median_duration' => 0 );
}
$GLOBALS['wpdb'] = new SR_Stub_wpdb();
sn_session_rollup_run();
ok( ! empty( $GLOBALS['__sr_metrics_pv'] ) && 3 !== max( $GLOBALS['__sr_metrics_pv'] ),
	'run: metrics never see the raw (unfiltered) 3-group set' );
ok( ! empty( $GLOBALS['__sr_metrics_pv'] ) && 2 === max( $GLOBALS['__sr_metrics_pv'] ),
	'run: metrics computed over the 2 pageview visits (phantom pageview-less group dropped)' );
// The default fixture's summaries carry NO exit paths → the bridge derives zero
// rows → pageroles is never touched (absent days write nothing, never 0-rows).
ok( array() === $GLOBALS['__sr_exit_upserts'],
	'run: no exit paths in the summaries → no pageroles upsert at all (nothing written)' );

// ── sn_session_exit_page_rows: the durable exit-pages bridge (v9.66.0) ───────
// Pure derivation: pv-gated visit summaries → pageroles-shaped role='exit'
// rows. Each visit ends on exactly ONE page (its last pageview), so a path's
// exit count is both its exit pageviews and its exiting visits: views ==
// visits == count. Day-key: the caller passes the session engine's UTC day
// (gmdate) — the SAME convention the pageroles table's live entry feed uses
// (sn_analytics_pageroles_rollup_sql buckets by toStartOfDay(timestamp), UTC
// midnight, no tz arg) — matching, not inventing a third convention.
echo "\nGroup: sn_session_exit_page_rows (pure exit-bridge derivation)\n";
$exit_rows = sn_session_exit_page_rows( array(
	array( 'exit' => '/notes/a', 'pageviews' => 2 ),
	array( 'exit' => '/notes/a', 'pageviews' => 1 ),
	array( 'exit' => '/about/',  'pageviews' => 1 ),
	array( 'exit' => '',         'pageviews' => 0 ), // pageview-less group: sn_visit_summary yields exit '' — skipped
	'not-an-array',                                   // malformed summary — skipped, never fatal
), '2026-06-01' );
ok( is_array( $exit_rows ) && 2 === count( $exit_rows ), 'exit-rows: two distinct exit paths → exactly two rows (blank exits + junk skipped)' );
$by_path = array();
foreach ( (array) $exit_rows as $r ) { $by_path[ $r['path'] ] = $r; }
ok( isset( $by_path['/notes/a'] ) && 2 === $by_path['/notes/a']['views'] && 2 === $by_path['/notes/a']['visits'],
	'exit-rows: /notes/a counted twice (views == visits == exit count)' );
ok( isset( $by_path['/about/'] ) && 1 === $by_path['/about/']['views'] && 1 === $by_path['/about/']['visits'],
	'exit-rows: /about/ counted once' );
ok( is_int( $by_path['/notes/a']['views'] ?? null ) && is_int( $by_path['/notes/a']['visits'] ?? null ),
	'exit-rows: counts are real ints (upsert-ready)' );
$shape_ok = true;
foreach ( (array) $exit_rows as $r ) {
	if ( ! is_array( $r ) || '2026-06-01' !== ( $r['day'] ?? '' ) || 'exit' !== ( $r['role'] ?? '' ) ) { $shape_ok = false; }
}
ok( $shape_ok, 'exit-rows: every row carries the caller\'s day and role=exit (pageroles upsert shape)' );
ok( array() === sn_session_exit_page_rows( array(), '2026-06-01' ), 'exit-rows: no summaries → no rows (an absent day writes NOTHING)' );
ok( array() === sn_session_exit_page_rows( array( array( 'exit' => '', 'pageviews' => 0 ) ), '2026-06-01' ),
	'exit-rows: only blank exits → no rows (never a zero-row)' );

// ── run() wires the exit bridge: human-only, pv-gated, engine day-key ────────
echo "\nGroup: run() upserts exit pages into pageroles (v9.66.0 bridge)\n";
$GLOBALS['__sr_exit_upserts'] = array();
$GLOBALS['wpdb']              = new SR_Stub_wpdb();
$GLOBALS['__sr_fetch']        = array( 'configured' => true, 'summaries' => array(
	array( 'pageviews' => 2, 'duration' => 40, 'engaged' => 1, 'exit' => '/notes/a' ),
	array( 'pageviews' => 1, 'duration' => 5,  'engaged' => 0, 'exit' => '/notes/a' ),
	array( 'pageviews' => 3, 'duration' => 60, 'engaged' => 1, 'exit' => '/about/' ),
	// pv-less group with a (theoretically impossible) non-blank exit: pins that
	// the bridge consumes the pv-GATED visit set, not the raw summaries.
	array( 'pageviews' => 0, 'duration' => 0,  'engaged' => 0, 'exit' => '/phantom' ),
) );
$expected_day = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
sn_session_rollup_run();
ok( 1 === count( $GLOBALS['__sr_exit_upserts'] ),
	'run: pageroles upsert called EXACTLY once (human class only — not once per class)' );
$up = $GLOBALS['__sr_exit_upserts'][0] ?? array();
ok( is_array( $up ) && 2 === count( $up ), 'run: two exit paths upserted' );
$up_by_path = array();
foreach ( (array) $up as $r ) { $up_by_path[ $r['path'] ] = $r; }
ok( isset( $up_by_path['/notes/a'] ) && 2 === $up_by_path['/notes/a']['views'] && 2 === $up_by_path['/notes/a']['visits'],
	'run: /notes/a exits twice across the two visits ending there' );
ok( isset( $up_by_path['/about/'] ) && 1 === $up_by_path['/about/']['views'] && 1 === $up_by_path['/about/']['visits'],
	'run: /about/ exits once' );
ok( ! isset( $up_by_path['/phantom'] ),
	'run: the pageview-less group NEVER reaches pageroles (bridge feeds on sn_pageview_visits output)' );
$day_ok = true;
foreach ( (array) $up as $r ) {
	if ( ( $r['day'] ?? '' ) !== $expected_day || 'exit' !== ( $r['role'] ?? '' ) ) { $day_ok = false; }
}
ok( $day_ok, "run: every upserted row keys the engine's UTC yesterday ($expected_day) with role=exit — the pageroles (UTC) day convention" );
unset( $GLOBALS['__sr_fetch'] );

// ── sn_session_rollup_read: the durable-table accessor (v9.65.0) ─────────────
// The writer (sn_session_rollup_run) keys rows by gmdate('Y-m-d') — a UTC day
// string — and MySQL/wpdb transports every selected column back as a STRING.
// The accessor must (a) never fabricate rows for absent days, (b) re-type the
// numeric strings deliberately, (c) return null (not []) when the query FAILS
// or the input is invalid — unknown is not an empty window.
echo "\nGroup: sn_session_rollup_read (typed rows, absent days absent)\n";
$GLOBALS['wpdb']          = new SR_Stub_wpdb();
$GLOBALS['wpdb']->results = array(
	// Day 2026-06-02 is deliberately MISSING (the cron skipped a night).
	array( 'day' => '2026-06-01', 'visits' => '12', 'bounce_pct' => '42.50', 'ppv' => '1.75', 'median_dur' => '55' ),
	array( 'day' => '2026-06-03', 'visits' => '7',  'bounce_pct' => '0.00',  'ppv' => '2.10', 'median_dur' => '61' ),
);
$rows = sn_session_rollup_read( '2026-06-01', '2026-06-03', 'human' );
ok( is_array( $rows ) && 2 === count( $rows ), 'read: two stored days → exactly two rows (absent 2026-06-02 stays ABSENT, never a fabricated 0-row)' );
ok( array( '2026-06-01', '2026-06-03' ) === array_column( (array) $rows, 'day' ), 'read: day keys pass through as the writer\'s Y-m-d strings, ascending' );
ok( 12 === $rows[0]['visits'] && is_int( $rows[0]['visits'] ), 'read: visits re-typed to int (wpdb transported "12")' );
ok( 42.5 === $rows[0]['bounce_pct'] && is_float( $rows[0]['bounce_pct'] ), 'read: bounce_pct re-typed to float (wpdb transported "42.50")' );
ok( 1.75 === $rows[0]['ppv'] && is_float( $rows[0]['ppv'] ), 'read: ppv re-typed to float' );
ok( 55 === $rows[0]['median_dur'] && is_int( $rows[0]['median_dur'] ), 'read: median_dur re-typed to int' );
ok( 0.0 === $rows[1]['bounce_pct'], 'read: a stored "0.00" comes back as a REAL 0.0 (zero is an answer)' );
$sql = end( $GLOBALS['wpdb']->queries );
ok( false !== strpos( $sql, "'2026-06-01'" ) && false !== strpos( $sql, "'2026-06-03'" ) && false !== strpos( $sql, "'human'" ),
	'read: the generated SQL binds both day bounds and the class' );
ok( false !== stripos( $sql, 'ORDER BY day ASC' ), 'read: rows come back day-ascending (trend order)' );

echo "\nGroup: sn_session_rollup_read (empty vs failed vs invalid)\n";
$GLOBALS['wpdb']          = new SR_Stub_wpdb();
$GLOBALS['wpdb']->results = array();
$empty = sn_session_rollup_read( '2026-06-01', '2026-06-03', 'human' );
ok( array() === $empty && '' === $GLOBALS['wpdb']->last_error,
	'read: an EMPTY result set WITH an empty last_error is an ANSWER — [] (no rolled-up days), not null' );
// The REAL failed-query shape (missing/corrupt wp_sn_session_daily): wpdb sets
// ->last_error non-empty and get_results() returns [] — indistinguishable from
// an honest empty window unless the accessor consults last_error. Served as []
// the trend panel would render "no rolled-up days" instead of "could not be
// read" — failure served as an answer.
$GLOBALS['wpdb']            = new SR_Stub_wpdb();
$GLOBALS['wpdb']->fail_with = "Table 'wp.wp_sn_session_daily' doesn't exist";
ok( null === sn_session_rollup_read( '2026-06-01', '2026-06-03', 'human' ),
	'read: a FAILED query (last_error set, get_results returns []) is null ("don\'t know") — failure never served as an empty window' );
// The only shape real wpdb reports as null: a falsy query string (prepare()
// failed) — get_results( null ) short-circuits. The is_array guard stays.
$GLOBALS['wpdb']          = new SR_Stub_wpdb();
$GLOBALS['wpdb']->results = null;
ok( null === sn_session_rollup_read( '2026-06-01', '2026-06-03', 'human' ), 'read: a null get_results (falsy query — prepare() failed) is null too' );
$GLOBALS['wpdb'] = new SR_Stub_wpdb();
ok( null === sn_session_rollup_read( '2026-06-01', '2026-06-03', 'martian' ), 'read: unknown class → null' );
ok( array() === $GLOBALS['wpdb']->queries, 'read: unknown class never reaches the DB' );
ok( null === sn_session_rollup_read( 'junk', '2026-06-03', 'human' ), 'read: malformed from-day → null' );
ok( null === sn_session_rollup_read( '2026-06-01', '03-06-2026', 'human' ), 'read: malformed to-day → null' );
ok( array() === $GLOBALS['wpdb']->queries, 'read: malformed days never reach the DB' );
// A malformed row (missing a selected column) is dropped, not padded with 0s.
$GLOBALS['wpdb']          = new SR_Stub_wpdb();
$GLOBALS['wpdb']->results = array(
	array( 'day' => '2026-06-01', 'visits' => '12', 'bounce_pct' => '42.50', 'ppv' => '1.75', 'median_dur' => '55' ),
	array( 'day' => '2026-06-02', 'visits' => '3', 'bounce_pct' => '10.00' ), // ppv + median_dur missing
);
$rows2 = sn_session_rollup_read( '2026-06-01', '2026-06-03', 'human' );
ok( is_array( $rows2 ) && 1 === count( $rows2 ) && '2026-06-01' === $rows2[0]['day'],
	'read: a malformed row is DROPPED (never padded with fabricated 0s)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
