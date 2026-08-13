<?php
/**
 * CLI fixture for the Defense gauges (fail-open visibility + IPv6-share
 * criterion). Standalone, no WP bootstrap, global-stub style (mirrors
 * tests/login-defense-analytics.php).
 *
 * The planned-row gate is "each gauge proven to move when the failure it
 * watches occurs": the render assertions here feed synthetic failure rows
 * and assert the rendered VALUE changes — a real fail-open cannot be fired
 * on demand, so the synthetic-row fixture is the honest substitute.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$fails  = 0;
$passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
// Models WP's real signature: the PLURAL is returned for every count except
// exactly 1, including 0. A stub that always returned $single would hide the
// singular/plural defect this fixture exists to pin.
function _n( $single, $plural, $number, $d = null ) { return 1 === (int) $number ? (string) $single : (string) $plural; }
function number_format_i18n( $n ) { return (string) $n; }

// Panel primitives: record open/close, echo the head so it is assertable.
function snt_an_panel_open( $head, $args = array() ) { echo '<panel>' . esc_html( $head ) . '</panel>'; }
function snt_an_panel_close() { echo '<!--/panel-->'; }

// Query stub routes BY SQL SHAPE (the shared-global stub in the sibling
// fixture cannot serve two different queries in one render). null = AE
// failure, array = measured — the real sn_analytics_query() contract.
$GLOBALS['__q_trend']  = array();
$GLOBALS['__q_family'] = array();
function sn_analytics_query( $sql ) {
	if ( false !== strpos( $sql, 'blob8' ) ) { return $GLOBALS['__q_family']; }
	if ( false !== strpos( $sql, 'failopen' ) ) { return $GLOBALS['__q_trend']; }
	return array();
}
$GLOBALS['__cfg'] = array( 'account_id' => 'x', 'token' => 'y' );
function sn_analytics_config() { return $GLOBALS['__cfg']; }

require __DIR__ . '/../inc/login-defense.php';
require __DIR__ . '/../inc/login-defense-gauges.php';

// --- SQL builders ------------------------------------------------------------
$t = sn_login_defense_failopen_trend_sql( 7 );
ok( strpos( $t, 'sn_login_guard' ) !== false, 'fail-open SQL targets sn_login_guard' );
ok( strpos( $t, "sum(if(blob2 = 'failopen', _sample_interval, 0)) AS failopen" ) !== false
	&& strpos( $t, "sum(if(blob2 = 'degraded', _sample_interval, 0)) AS degraded" ) !== false,
	'fail-open SQL: conditional de-sampled sums for BOTH open-door states' );
ok( strpos( $t, "INTERVAL '7'" ) !== false && strpos( $t, 'GROUP BY day ORDER BY day' ) !== false,
	'fail-open SQL: day window, grouped + ordered' );
ok( strpos( $t, 'count(*)' ) === false, 'fail-open SQL never uses count(*) (AE 422s it)' );

$f = sn_login_defense_family_share_sql( 30 );
ok( strpos( $f, 'blob8 AS family' ) !== false && strpos( $f, 'sum(_sample_interval)' ) !== false
	&& strpos( $f, "INTERVAL '30'" ) !== false && strpos( $f, 'GROUP BY family' ) !== false,
	'family SQL: blob8 alias, de-sampled, 30d, grouped' );
ok( strpos( $f, 'min(timestamp)' ) !== false && strpos( $f, 'first_seen' ) !== false,
	'family SQL carries the sensor birth (min(timestamp) AS first_seen) so the window can be MEASURED, not assumed' );
// LIVE DEFECT 2026-08-12: the panel rendered "coverage unknown" against real
// data — first_seen never arrived. The first version wrapped the aggregate in a
// scalar function, `formatDateTime(min(timestamp), …)`, which is the ONLY such
// construct in this repo and was never verified against the API. min() and
// formatDateTime() are each supported; nesting them was the untested guess.
// Select the aggregate RAW and format in PHP, where it is testable.
ok( strpos( $f, 'formatDateTime(min(' ) === false,
	'family SQL does NOT nest an aggregate inside a scalar function — the untested construct that returned nothing live' );
ok( strpos( $f, 'min(timestamp) AS first_seen' ) !== false,
	'family SQL selects the aggregate raw, so whatever shape AE returns is parsed in PHP' );

// --- AE timestamp parsing, the half the stubs could not reach ---------------
// The old fixtures fed rows that ALREADY contained a first_seen in the exact
// shape the reducer wanted, so they proved the reducer and nothing about the
// API. Pin the SHAPES instead: AE may return a space-separated datetime, an
// ISO-8601 with Z, or one with an offset. All three must parse to the same
// instant, and anything unparseable must yield null (never a fabricated span).
$t_expected = strtotime( '2026-07-22 18:00:00 UTC' );
foreach ( array(
	'2026-07-22 18:00:00'      => 'space-separated (ClickHouse DateTime)',
	'2026-07-22T18:00:00Z'     => 'ISO-8601 with Z',
	'2026-07-22T18:00:00+00:00' => 'ISO-8601 with a zero offset',
) as $raw => $label ) {
	ok( $t_expected === sn_login_defense_parse_ae_ts( $raw ),
		"AE timestamp parses: $label" );
}
ok( null === sn_login_defense_parse_ae_ts( '' ), 'AE timestamp: empty string -> null, never epoch 0' );
ok( null === sn_login_defense_parse_ae_ts( 'not-a-date' ), 'AE timestamp: garbage -> null, never a fabricated span' );
ok( null === sn_login_defense_parse_ae_ts( null ), 'AE timestamp: null -> null' );
// All three shapes must resolve to the SAME instant. (An earlier version of
// this pin claimed a blind " UTC" suffix would corrupt the Z form. Checked:
// it does not — PHP tolerates the doubled zone, and a real embedded offset
// wins over the suffix. The guard that claim justified has been removed.)
ok( sn_login_defense_parse_ae_ts( '2026-07-22T18:00:00Z' ) === sn_login_defense_parse_ae_ts( '2026-07-22 18:00:00' ),
	'AE timestamp: the Z form and the space form resolve to the same instant' );
// A NON-ZERO offset must be honoured, not flattened to the appended zone.
ok( strtotime( '2026-07-22 16:00:00 UTC' ) === sn_login_defense_parse_ae_ts( '2026-07-22T18:00:00+02:00' ),
	'AE timestamp: a +02:00 offset resolves to 16:00Z — the embedded offset wins over the appended UTC' );

// --- reducers ----------------------------------------------------------------
$tot = sn_login_defense_failopen_totals( array(
	array( 'day' => '2026-08-07', 'failopen' => 3, 'degraded' => 0 ),
	array( 'day' => '2026-08-08', 'failopen' => 1, 'degraded' => 2 ),
), 7 );
ok( $tot['failopen'] === 4 && $tot['degraded'] === 2, 'fail-open totals sum across days (4, 2)' );

// The trend query groups EVERY guard row by day, so the day rows it returns are
// themselves the coverage record: a day the guard logged nothing at all yields
// no row. A zero over days that were never watched is not a healthy zero.
ok( 2 === $tot['days_covered'] && '2026-08-07' === $tot['first_day'] && false === $tot['window_complete'],
	'fail-open totals carry coverage: 2 of 7 days logged, earliest named, window incomplete' );

$seven = array();
for ( $i = 0; $i < 7; $i++ ) {
	$seven[] = array( 'day' => gmdate( 'Y-m-d', time() - ( $i * 86400 ) ), 'failopen' => 0, 'degraded' => 0 );
}
$cov = sn_login_defense_failopen_totals( $seven, 7 );
ok( 7 === $cov['days_covered'] && true === $cov['window_complete'],
	'fail-open totals: 7 logged days over a 7d window is a COMPLETE window' );

$empty = sn_login_defense_failopen_totals( array(), 7 );
ok( 0 === $empty['failopen'] && 0 === $empty['days_covered'] && false === $empty['window_complete'],
	'fail-open totals: zero rows is zero COVERAGE, not a measured zero' );

$s = sn_login_defense_ipv6_share( array(
	array( 'family' => 'v4', 'hits' => 90 ),
	array( 'family' => 'v6', 'hits' => 5 ),
	array( 'family' => 'unknown', 'hits' => 5 ),
) );
ok( 5.0 === $s['share_pct'] && 100 === $s['total'] && false === $s['crossed'],
	'IPv6 share: unknown family counts in the denominator; 5.0% does NOT cross a >5 threshold' );
$s2 = sn_login_defense_ipv6_share( array(
	array( 'family' => 'v4', 'hits' => 89 ),
	array( 'family' => 'v6', 'hits' => 11 ),
) );
ok( 11.0 === $s2['share_pct'] && true === $s2['crossed'], 'IPv6 share: 11% crosses the criterion' );
ok( null === sn_login_defense_ipv6_share( array() )['share_pct'],
	'IPv6 share: zero rows -> share null (never-measured is not 0%)' );

// The window the criterion asks for is 30 days OF SENSOR COVERAGE. blob8 shipped
// with worker v1.5.0, so a "30d" query can return a share off 3 days of rows and
// say nothing about it. measured_days comes from the data, not from the query.
$now   = strtotime( '2026-08-12 00:00:00 UTC' );
$short = sn_login_defense_ipv6_share( array(
	array( 'family' => 'v4', 'hits' => 80, 'first_seen' => '2026-07-23 00:00:00' ),
	array( 'family' => 'v6', 'hits' => 20, 'first_seen' => '2026-07-25 00:00:00' ),
), 30, $now );
ok( 20 === $short['measured_days'],
	'measured window = now - EARLIEST first_seen across families (20d), not the 30d asked for' );
ok( false === $short['window_complete'] && true === $short['crossed'],
	'a crossed share on a 20-of-30 day window is crossed but NOT sustained' );

$full = sn_login_defense_ipv6_share( array(
	array( 'family' => 'v4', 'hits' => 80, 'first_seen' => '2026-06-01 00:00:00' ),
	array( 'family' => 'v6', 'hits' => 20, 'first_seen' => '2026-06-01 00:00:00' ),
), 30, $now );
ok( true === $full['window_complete'] && $full['measured_days'] >= 30,
	'a sensor older than the criterion window gives a COMPLETE window (clamped at the 30d query bound)' );

ok( null === sn_login_defense_ipv6_share( array(
	array( 'family' => 'v4', 'hits' => 80 ),
), 30, $now )['window_complete'],
	'no first_seen -> window_complete is null (unknown coverage is not proven coverage)' );

// --- render: the proven-to-move gate ----------------------------------------
function render_gauges_html() { ob_start(); sn_login_defense_render_gauges( 7 ); return ob_get_clean(); }

// Healthy: measured zeros render EXPLICITLY (absence of failure is a claim) —
// but only over days the guard actually logged, so the fixture supplies them.
$GLOBALS['__q_trend']  = $seven;
$GLOBALS['__q_family'] = array( array( 'family' => 'v4', 'hits' => 100 ) );
$h = render_gauges_html();
ok( strpos( $h, 'Defense gauges' ) !== false, 'gauges panel renders with its heading' );
ok( strpos( $h, '0 fail-opens' ) !== false && strpos( $h, '0 degraded' ) !== false,
	'healthy: zero renders as an explicit "0", never by omission' );
ok( strpos( $h, '7 of 7 days' ) !== false,
	'healthy: the zero is qualified by the days the guard actually logged (7 of 7)' );
ok( strpos( $h, '0%' ) !== false && strpos( $h, 'below' ) !== false,
	'healthy: 0% IPv6 share reads below the criterion' );

// A zero over an unwatched window is the reassuring zero this whole panel
// exists to refuse. No day rows at all = no telemetry, and that is not "0".
$GLOBALS['__q_trend'] = array();
$z = render_gauges_html();
ok( strpos( $z, '0 fail-opens' ) === false && stripos( $z, 'no telemetry' ) !== false,
	'no logged days: the gauge says NO TELEMETRY, never a reassuring "0 fail-opens"' );

// Partial coverage: the count is real but it is a count for the days that
// exist, and the gauge says which.
$GLOBALS['__q_trend'] = array(
	array( 'day' => gmdate( 'Y-m-d', time() - 86400 ), 'failopen' => 2, 'degraded' => 0 ),
	array( 'day' => gmdate( 'Y-m-d', time() ), 'failopen' => 0, 'degraded' => 0 ),
);
$pc = render_gauges_html();
ok( strpos( $pc, '2 fail-opens' ) !== false && strpos( $pc, '2 of 7 days' ) !== false,
	'partial coverage: the count is named over the days it covers (2 of 7), not the window asked for' );

// PROVEN TO MOVE 1: synthetic fail-open days move the number 0 -> 4.
$GLOBALS['__q_trend'] = array(
	array( 'day' => '2026-08-07', 'failopen' => 3, 'degraded' => 1 ),
	array( 'day' => '2026-08-08', 'failopen' => 1, 'degraded' => 0 ),
);
$m = render_gauges_html();
ok( strpos( $m, '4 fail-opens' ) !== false && strpos( $m, '1 degraded' ) !== false,
	'PROVEN TO MOVE: fail-open gauge changes when fail-open rows exist (4, 1)' );
// LIVE 2026-08-12: rendered "The other 1 hold no telemetry" — a plain __() where
// the count varies. Singular and plural both have to read as English.
ok( strpos( $m, 'The other 5 days hold no telemetry' ) !== false,
	'partial coverage, plural: "The other 5 days hold"' );
$GLOBALS['__q_trend'] = array(
	array( 'day' => '2026-08-07', 'failopen' => 0, 'degraded' => 0 ),
	array( 'day' => '2026-08-08', 'failopen' => 0, 'degraded' => 0 ),
	array( 'day' => '2026-08-09', 'failopen' => 0, 'degraded' => 0 ),
	array( 'day' => '2026-08-10', 'failopen' => 0, 'degraded' => 0 ),
	array( 'day' => '2026-08-11', 'failopen' => 0, 'degraded' => 0 ),
	array( 'day' => '2026-08-12', 'failopen' => 0, 'degraded' => 0 ),
);
$one = render_gauges_html();
ok( strpos( $one, 'The other 1 day holds no telemetry' ) !== false,
	'partial coverage, SINGULAR: "The other 1 day holds" — the live grammar defect' );
ok( strpos( $one, 'The other 1 hold' ) === false,
	'…and the ungrammatical form is gone' );

// Windows are relative to the run, so the fixtures date first_seen off time()
// rather than pinning a calendar day that would rot.
$ago = function ( $d ) { return gmdate( 'Y-m-d H:i:s', time() - ( $d * 86400 ) ); };

// PROVEN TO MOVE 2: a v6-heavy month, over a COMPLETE window, flips below ->
// crossed and names the decision.
$GLOBALS['__q_family'] = array(
	array( 'family' => 'v4', 'hits' => 80, 'first_seen' => $ago( 45 ) ),
	array( 'family' => 'v6', 'hits' => 20, 'first_seen' => $ago( 45 ) ),
);
$c = render_gauges_html();
ok( strpos( $c, '20%' ) !== false && strpos( $c, 'crossed' ) !== false,
	'PROVEN TO MOVE: IPv6 gauge crosses at 20%' );
ok( stripos( $c, '128-bit' ) !== false,
	'crossing names the decision it triggers (build 128-bit ranges), not just the number' );
ok( strpos( $c, '30d measured' ) !== false,
	'a complete window is NAMED as measured, not left implied by the query bound' );

// The criterion is "5% sustained over 30 days". On a short window the share is
// real but the criterion is not yet satisfiable — the gauge must not announce a
// decision the data cannot authorise.
$GLOBALS['__q_family'] = array(
	array( 'family' => 'v4', 'hits' => 80, 'first_seen' => $ago( 20 ) ),
	array( 'family' => 'v6', 'hits' => 20, 'first_seen' => $ago( 12 ) ),
);
$p = render_gauges_html();
ok( strpos( $p, '20%' ) !== false && strpos( $p, '20d measured of 30d' ) !== false,
	'short window: the MEASURED span is named next to the share (20d measured of 30d)' );
ok( stripos( $p, 'not yet' ) !== false && stripos( $p, '128-bit denylist ranges.' ) === false,
	'short window: crossed but NOT sustained — the gauge withholds the decision instead of triggering it' );

// Unknown coverage is not proven coverage: rows without first_seen cannot claim
// a sustained window either.
$GLOBALS['__q_family'] = array(
	array( 'family' => 'v4', 'hits' => 80 ),
	array( 'family' => 'v6', 'hits' => 20 ),
);
$n = render_gauges_html();
ok( stripos( $n, 'coverage unknown' ) !== false,
	'no first_seen: the window is reported as unknown coverage, never as a full 30d' );

// Zero-vs-null honesty: AE failure renders "unknown", never a fake zero.
$GLOBALS['__q_trend']  = null;
$GLOBALS['__q_family'] = null;
$u = render_gauges_html();
ok( stripos( $u, 'unknown' ) !== false && strpos( $u, '0 fail-opens' ) === false,
	'AE failure: gauges say "unknown", never a reassuring zero' );

// Mount guard: the view body must call the gauges (a module that renders
// perfectly but is mounted nowhere is the admin-registry lesson). Source-level
// because the sibling fixture owns render_body's behavioral assertions.
$body_src = (string) file_get_contents( __DIR__ . '/../inc/login-defense-analytics.php' );
ok( strpos( $body_src, 'sn_login_defense_render_gauges( $days )' ) !== false,
	'render_body mounts the gauges panel' );
$main_src = (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
ok( strpos( $main_src, "inc/login-defense-gauges.php" ) !== false,
	'the plugin bootstrap requires the gauges module' );

echo "\nResult: $passes passed, $fails failed.\n";
exit( $fails > 0 ? 1 : 0 );
