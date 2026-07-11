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
}
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
	// Two real pageview visits + one pageview-less server/RSS group (the phantom).
	return array( 'configured' => true, 'summaries' => array(
		array( 'pageviews' => 2, 'duration' => 40, 'engaged' => 1 ),
		array( 'pageviews' => 1, 'duration' => 5,  'engaged' => 0 ),
		array( 'pageviews' => 0, 'duration' => 0,  'engaged' => 0 ), // RSS 'ce' poll — NOT a visit
	) );
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
