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
ok( null === $short['window_complete'] && true === $short['crossed'],
	'a crossed share on rows carrying NO day dimension: crossed, but coverage unknowable -> null, never a decision' );

$full = sn_login_defense_ipv6_share( array(
	array( 'family' => 'v4', 'hits' => 80, 'first_seen' => '2026-06-01 00:00:00' ),
	array( 'family' => 'v6', 'hits' => 20, 'first_seen' => '2026-06-01 00:00:00' ),
), 30, $now );
ok( null === $full['window_complete'] && $full['measured_days'] >= 30,
	'RE-SPEC: a sensor older than the window no longer COMPLETES it — age is not coverage, and these rows carry no days' );

ok( null === sn_login_defense_ipv6_share( array(
	array( 'family' => 'v4', 'hits' => 80 ),
), 30, $now )['window_complete'],
	'no first_seen -> window_complete is null (unknown coverage is not proven coverage)' );

// LIVE DEFECT 2026-08-12: the panel dated the family sensor to 2026-07-18 —
// four days before blob8 shipped in worker v1.5.0 (tagged 2026-07-22). Rows
// written before the sensor have no blob8; AE groups them under family ''.
// That empty group is not a measurement. It is the absence of the instrument.
// Including it overstated coverage (min first_seen predates the sensor) and
// understated the share (pre-sensor hits sat in the denominator, never in v6).
$sensor_only = array(
	array( 'family' => 'v4', 'hits' => 80, 'first_seen' => '2026-07-23 00:00:00' ),
	array( 'family' => 'v6', 'hits' => 20, 'first_seen' => '2026-07-25 00:00:00' ),
);
$with_empty = array_merge( $sensor_only, array(
	array( 'family' => '', 'hits' => 50, 'first_seen' => '2026-07-13 00:00:00' ),
) );
$base  = sn_login_defense_ipv6_share( $sensor_only, 30, $now );
$empty = sn_login_defense_ipv6_share( $with_empty, 30, $now );
ok( $base['share_pct'] === $empty['share_pct'] && 20.0 === $empty['share_pct'],
	'empty-family is excluded from the denominator: same v4/v6 hits keep the same share' );
ok( $base['first_seen'] === $empty['first_seen'] && $base['measured_days'] === $empty['measured_days'],
	'empty-family does NOT lower first_seen or inflate measured_days' );
ok( null === $empty['window_complete'] && 20 === $empty['measured_days'],
	'empty-family must not complete the window: pre-sensor rows cannot manufacture coverage the sensor never had' );
ok( 50 === $empty['pre_sensor_hits'] && 0 === $base['pre_sensor_hits'],
	'pre_sensor_hits reports the excluded count exactly (50), and is 0 when nothing was dropped' );
ok( 100 === $empty['total'] && 100 === $base['total'],
	'empty-family hits never enter total — the denominator is sensor-covered traffic only' );

// REGRESSION GUARD: 'unknown' is the opposite kind of string. The sensor WAS
// present and could not parse the address — still attacker-reachable surface.
// An over-correction that dropped it would flatter the share. The share MUST
// change when an unknown row is added, and its first_seen MUST participate.
$with_unknown = array_merge( $sensor_only, array(
	array( 'family' => 'unknown', 'hits' => 25, 'first_seen' => '2026-07-18 00:00:00' ),
) );
$unk = sn_login_defense_ipv6_share( $with_unknown, 30, $now );
ok( $unk['share_pct'] !== $base['share_pct'] && 16.0 === $unk['share_pct'] && 125 === $unk['total'],
	'REGRESSION: unknown stays in the denominator — adding it CHANGES the share (20% -> 16%)' );
ok( '2026-07-18' === $unk['first_seen'] && $unk['measured_days'] > $base['measured_days'],
	'REGRESSION: unknown first_seen still participates (sensor was present, address unparseable)' );
ok( 0 === $unk['pre_sensor_hits'],
	'unknown is not pre-sensor: pre_sensor_hits stays 0' );

$all_pre = sn_login_defense_ipv6_share( array(
	array( 'family' => '', 'hits' => 77, 'first_seen' => '2026-07-13 00:00:00' ),
), 30, $now );
ok( null === $all_pre['share_pct'] && null === $all_pre['measured_days'] && null === $all_pre['window_complete'],
	'all-empty-family rows: share_pct, measured_days, window_complete are all null (never-measured, not 0%)' );
ok( 77 === $all_pre['pre_sensor_hits'] && 0 === $all_pre['total'],
	'all-empty-family rows still report the excluded hits; total is 0' );

// ── COVERAGE IS NOT SPAN (2026-08-27) ────────────────────────────────────────
// measured_days is min($days, now - first_seen): a SPAN. It cannot tell 30 days
// of daily rows apart from two rows 30 days apart, because the family query
// GROUPed BY family and aggregated the day dimension away. The reducer docblock
// claimed min(timestamp) "measures coverage" — true only while traffic is dense
// enough that EVERY day writes a row, a precondition the live sensor stopped
// meeting: across five readings first_seen slid 07-26 -> 07-30 while
// measured_days went 27 -> 29 -> 28 -> 28, which only happens when boundary days
// hold no rows at all. days_covered is the real count, off a day dimension the
// query now carries.

$sparse = sn_login_defense_ipv6_share( array(
	array( 'family' => 'v4', 'hits' => 40, 'first_seen' => '2026-07-13 00:00:00', 'day' => '2026-07-13' ),
	array( 'family' => 'v6', 'hits' => 10, 'first_seen' => '2026-08-12 00:00:00', 'day' => '2026-08-12' ),
), 30, $now );
ok( 2 === $sparse['days_covered'],
	'days_covered counts DAYS THAT WROTE ROWS (2), not the span between them' );
ok( 30 === $sparse['measured_days'] && false === $sparse['window_complete'],
	'THE FIX, PINNED: those two rows still SPAN a full 30d, and no longer complete the window — span and decision are decoupled' );

// The opposite population: every day in the window wrote. 30 calendar days
// straddle a 29-day span, so days_covered is capped at the window asked for
// (same reasoning as the fail-open reducer's cap).
$dense_rows = array();
for ( $i = 0; $i < 30; $i++ ) {
	$d            = gmdate( 'Y-m-d', $now - ( $i * 86400 ) );
	$dense_rows[] = array( 'family' => 'v4', 'hits' => 3, 'first_seen' => $d . ' 00:00:00', 'day' => $d );
}
$dense = sn_login_defense_ipv6_share( $dense_rows, 30, $now );
ok( 30 === $dense['days_covered'],
	'days_covered = 30 when every day in the window wrote a row (capped at the window asked for)' );

// GROUP BY family, day emits ONE ROW PER (family, day): a day carrying v4, v6 and
// an unparseable address is three rows and still ONE covered day. Counting rows
// here would inflate coverage by roughly the family count on live traffic, where
// most days carry both v4 and v6.
$one_day = sn_login_defense_ipv6_share( array(
	array( 'family' => 'v4', 'hits' => 30, 'first_seen' => '2026-08-11 00:00:00', 'day' => '2026-08-11' ),
	array( 'family' => 'v6', 'hits' => 20, 'first_seen' => '2026-08-11 01:00:00', 'day' => '2026-08-11' ),
	array( 'family' => 'unknown', 'hits' => 5, 'first_seen' => '2026-08-11 02:00:00', 'day' => '2026-08-11' ),
), 30, $now );
ok( 1 === $one_day['days_covered'] && 55 === $one_day['total'],
	'three family rows on ONE day is 1 covered day, not 3 (distinct days, never row count)' );

// Pre-sensor days are the absence of the instrument, not an idle guard. They are
// already out of the denominator and out of first_seen; they must stay out of
// coverage too, or a sensor that shipped yesterday would claim a covered month.
$cov_pre = sn_login_defense_ipv6_share( array(
	array( 'family' => 'v4', 'hits' => 40, 'first_seen' => '2026-08-11 00:00:00', 'day' => '2026-08-11' ),
	array( 'family' => '', 'hits' => 50, 'first_seen' => '2026-07-13 00:00:00', 'day' => '2026-07-13' ),
), 30, $now );
ok( 1 === $cov_pre['days_covered'],
	'days_covered excludes pre-sensor days: the sensor was absent, never proven idle' );

ok( null === $base['days_covered'],
	'rows with no day dimension -> days_covered null (unknown coverage is not zero coverage)' );

ok( strpos( $f, 'toStartOfDay' ) !== false && strpos( $f, 'GROUP BY family, day' ) !== false,
	'family SQL carries a day dimension: per-day coverage is unknowable without it' );

// ── THE RE-SPEC (owner decision, 2026-08-27) ────────────────────────────────
// The old coverage half asked for 30 days of SPAN and could not be satisfied:
// measured live, ~14% of days carry no block-eligible traffic at all, so
// coverage plateaus near 26/30 and 30/30 is unreachable. A criterion that
// cannot be satisfied is not a high bar, it is a broken instrument. The rule
// now asks for what "sustained" actually means at this volume: enough COVERED
// DAYS and enough OBSERVATIONS.
ok( 20 === SN_LG_IPV6_MIN_DAYS_COVERED && 100 === SN_LG_IPV6_MIN_OBSERVATIONS,
	'the re-specced halves are named constants, not literals buried in a branch' );
ok( 30 === SN_LG_IPV6_CRITERION_DAYS,
	'the 30d LOOKBACK survives the re-spec — only the coverage requirement changed' );

$mkdays = function ( $n_days, $v4_each, $v6_map, $now ) {
	$rows = array();
	for ( $i = 0; $i < $n_days; $i++ ) {
		$d = gmdate( 'Y-m-d', $now - ( $i * 86400 ) );
		$rows[] = array( 'family' => 'v4', 'hits' => $v4_each, 'first_seen' => $d . ' 00:00:00', 'day' => $d );
		if ( isset( $v6_map[ $i ] ) ) {
			$rows[] = array( 'family' => 'v6', 'hits' => $v6_map[ $i ], 'first_seen' => $d . ' 01:00:00', 'day' => $d );
		}
	}
	return $rows;
};

// Enough days AND enough observations: both halves hold.
$ok_rows = $mkdays( 25, 4, array( 0 => 40 ), $now );   // 25 days, 100 v4 + 40 v6 = 140
$respec  = sn_login_defense_ipv6_share( $ok_rows, 30, $now );
ok( 25 === $respec['days_covered'] && 140 === $respec['total'] && true === $respec['window_complete'],
	'RE-SPEC: 25 covered days and 140 observations SATISFIES the criterion (the old rule refused this forever)' );
ok( $respec['measured_days'] < 30,
	'and it satisfies it on a span SHORTER than 30d — measured_days no longer gates the decision' );

// Enough observations, too few days: a burst is not "sustained".
$burst = sn_login_defense_ipv6_share( $mkdays( 8, 30, array( 0 => 60 ), $now ), 30, $now );
ok( 8 === $burst['days_covered'] && $burst['total'] >= 100 && false === $burst['window_complete'],
	'RE-SPEC: 300 observations over only 8 days is NOT sustained — the days half still bites' );

// Enough days, too few observations: a trickle is not a measurement.
$thin = sn_login_defense_ipv6_share( $mkdays( 25, 1, array( 0 => 20 ), $now ), 30, $now );
ok( 25 === $thin['days_covered'] && $thin['total'] < 100 && false === $thin['window_complete'],
	'RE-SPEC: 25 days holding only 45 observations is NOT enough evidence — the observations half still bites' );

// Unknown coverage stays unknown. Never-measured is not a satisfied criterion.
ok( null === sn_login_defense_ipv6_share( array(
	array( 'family' => 'v4', 'hits' => 500 ),
), 30, $now )['window_complete'],
	'RE-SPEC: no day dimension -> window_complete NULL, never true off an unmeasurable window' );

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

// PROVEN TO MOVE 2: a v6-heavy month, over a window that SATISFIES the
// re-specced coverage halves, flips below -> crossed and names the decision.
// The fixture now models what AE actually returns: one row per (family, day).
$GLOBALS['__q_family'] = array();
for ( $i = 0; $i < 25; $i++ ) {
	$d = gmdate( 'Y-m-d', time() - ( $i * 86400 ) );
	$GLOBALS['__q_family'][] = array( 'family' => 'v4', 'hits' => 4, 'first_seen' => $d . ' 00:00:00', 'day' => $d );
	if ( $i < 5 ) {
		$GLOBALS['__q_family'][] = array( 'family' => 'v6', 'hits' => 5, 'first_seen' => $d . ' 01:00:00', 'day' => $d );
	}
}
// 25 covered days, 100 v4 + 25 v6 = 125 observations -> both halves hold.
$c = render_gauges_html();
ok( strpos( $c, '20%' ) !== false && strpos( $c, 'crossed' ) !== false,
	'PROVEN TO MOVE: IPv6 gauge crosses at 20%' );
ok( stripos( $c, '128-bit' ) !== false,
	'crossing names the decision it triggers (build 128-bit ranges), not just the number' );
ok( strpos( $c, '25 of 30 days covered' ) !== false,
	'RE-SPEC: a satisfied window is named by its COVERAGE, not by the span the query asked for' );

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

// A window can be COMPLETE BY SPAN and nearly empty by coverage. The panel must
// disclose the covered-day count, or a reader takes "30d measured" to mean 30
// days of data — the exact substitution that made this criterion unreadable.
$day_ago = function ( $d ) { return gmdate( 'Y-m-d', time() - ( $d * 86400 ) ); };
$GLOBALS['__q_family'] = array(
	array( 'family' => 'v4', 'hits' => 80, 'first_seen' => $ago( 30 ), 'day' => $day_ago( 30 ) ),
	array( 'family' => 'v6', 'hits' => 20, 'first_seen' => $ago( 0 ), 'day' => $day_ago( 0 ) ),
);
$sp = render_gauges_html();
ok( strpos( $sp, '2 of 30 days covered' ) !== false,
	'sparse window: the panel names DAYS COVERED (2 of 30) beside the 30d span' );
ok( strpos( $sp, '30d measured' ) !== false,
	'the span is still reported — coverage is added beside it, not swapped for it' );

// Where the rows carry no day dimension the panel must stay silent about
// coverage rather than print a fabricated count.
ok( strpos( $p, 'days covered' ) === false,
	'no day dimension: the panel omits the coverage clause instead of inventing one' );

// Pre-sensor hits belong beside the measured-window clause: same fact, what
// the sensor did and did not see. Named when they exist; no phantom clause
// when they do not.
$GLOBALS['__q_trend']  = $seven;
$GLOBALS['__q_family'] = array(
	array( 'family' => 'v4', 'hits' => 80, 'first_seen' => $ago( 20 ) ),
	array( 'family' => 'v6', 'hits' => 20, 'first_seen' => $ago( 12 ) ),
	array( 'family' => '', 'hits' => 40, 'first_seen' => $ago( 45 ) ),
);
$pre_html = render_gauges_html();
ok( strpos( $pre_html, '20%' ) !== false && strpos( $pre_html, '40 pre-sensor hits excluded' ) !== false,
	'render: pre-sensor hits are named beside the measured window; share stays undiluted' );
$GLOBALS['__q_family'] = array(
	array( 'family' => 'v4', 'hits' => 80, 'first_seen' => $ago( 20 ) ),
	array( 'family' => 'v6', 'hits' => 20, 'first_seen' => $ago( 12 ) ),
);
$no_pre_html = render_gauges_html();
ok( strpos( $no_pre_html, 'pre-sensor' ) === false,
	'render: no phantom pre-sensor clause when pre_sensor_hits is 0' );

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
