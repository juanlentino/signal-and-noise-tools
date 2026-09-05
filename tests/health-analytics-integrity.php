<?php
/**
 * Standalone fixture tests for inc/health-analytics-integrity.php (v9.65.0).
 *
 * Phase A's P0.4 closed for real: the never-invert guard (rollup + read side)
 * has written `sn_analytics_integrity_alert` since v9.63.0, but NOTHING read
 * it — the docblocks claimed "the Health scan reads it" while the alarm rang
 * into a void. This suite drives the REAL check callback through the full
 * behavior matrix (no option / fresh option / stale option / corrupt option)
 * and pins the wiring the standalone harness cannot execute (the
 * sn_health_run_scan() registration + the plugin-loader require) via source
 * containment.
 *
 * Run: php tests/health-analytics-integrity.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = '' ) { return $s; } }

// Option store — the check READS ONLY (it must never clear or mutate the alert).
$GLOBALS['__hai_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__hai_options'] ) ? $GLOBALS['__hai_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) { $GLOBALS['__hai_options'][ $key ] = $value; return true; }

// sn_health_pack_check lives in inc/health-checks.php (not loadable standalone —
// it declares 11 sibling checks + WP-heavy code). Mirror the REAL envelope
// builder exactly (inc/health-checks.php:1237-1244) — count/findings/label/fix_hint.
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
		'skipped'  => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null,
	);
}

require __DIR__ . '/../inc/health-analytics-integrity.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$NOW = 1789000000; // fixed clock for the pure builder

// ── Pure findings builder ────────────────────────────────────────────────────
echo "\nGroup: findings builder — absent passes, present-but-corrupt FLAGS\n";
ok( array() === sn_health_analytics_integrity_findings( false, $NOW ), 'absent option (false, get_option\'s default) → zero findings' );
// A PRESENT-but-non-array value (mangled import, serialized garbage) is NOT
// "no violations": the alarm's only reader must never fail toward silence.
$corrupt_f = sn_health_analytics_integrity_findings( 'corrupt', $NOW );
ok( 1 === count( $corrupt_f ), 'present-but-non-array option → one finding (corrupt record flags, never a green pass)' );
ok( false !== strpos( $corrupt_f[0]['note'] ?? '', 'unreadable (corrupt)' ), 'corrupt finding says the record is present but unreadable' );
ok( false !== strpos( $corrupt_f[0]['note'] ?? '', 'wp option delete sn_analytics_integrity_alert' ), 'corrupt finding names the explicit clear command' );
ok( 'analytics_integrity' === ( $corrupt_f[0]['subject_type'] ?? '' ), 'corrupt finding carries the analytics_integrity subject type' );

echo "\nGroup: findings builder — rollup-shape payload (fresh)\n";
$rollup_alert = array(
	'time'            => $NOW - 3 * 3600, // 3h ago — same day
	'day'             => '2026-07-16',
	'path'            => '/',
	'class'           => 'human',
	'views'           => 2,
	'pageview_visits' => 5,
);
$f = sn_health_analytics_integrity_findings( $rollup_alert, $NOW );
ok( 1 === count( $f ), 'rollup shape → exactly one finding' );
ok( 'analytics_integrity' === ( $f[0]['subject_type'] ?? '' ), 'finding carries the analytics_integrity subject type' );
ok( false !== strpos( $f[0]['note'], 'views < pageview_visits' ), 'note names the inverted invariant' );
ok( false !== strpos( $f[0]['note'], '(2 < 5)' ), 'note pins the recorded values (2 < 5)' );
ok( false !== strpos( $f[0]['note'], '2026-07-16' ) && false !== strpos( $f[0]['subject_label'], '2026-07-16' ),
	'note + label carry the violation day (rollup scope)' );
ok( false !== strpos( $f[0]['note'], 'today' ), 'a same-day violation reads "today", not "0d ago"' );

echo "\nGroup: findings builder — read-range payload (stale: never auto-expires)\n";
$read_alert = array(
	'time'            => $NOW - ( 9 * DAY_IN_SECONDS ) - 7200, // 9 days + 2h ago
	'scope'           => 'read-range',
	'from'            => '2026-07-01',
	'to'              => '2026-07-07',
	'class'           => 'human',
	'views'           => 40,
	'pageview_visits' => 47,
);
$f2 = sn_health_analytics_integrity_findings( $read_alert, $NOW );
ok( 1 === count( $f2 ), 'a 9-day-old violation STILL yields a finding — a record never silently expires' );
ok( false !== strpos( $f2[0]['note'], '9d ago' ), 'staleness is stated honestly: "last violation 9d ago"' );
ok( false !== strpos( $f2[0]['note'], '2026-07-01..2026-07-07' ) && false !== strpos( $f2[0]['subject_label'], '2026-07-01..2026-07-07' ),
	'note + label carry the read-range scope' );
ok( false !== strpos( $f2[0]['note'], '(40 < 47)' ), 'note pins the recorded read-range values' );

echo "\nGroup: findings builder — timestamp missing\n";
$no_time = array( 'day' => '2026-07-10', 'path' => '/x', 'class' => 'human', 'views' => 1, 'pageview_visits' => 3 );
$f3      = sn_health_analytics_integrity_findings( $no_time, $NOW );
ok( 1 === count( $f3 ), 'a payload without a timestamp still counts as a violation' );
ok( false !== strpos( $f3[0]['note'], 'unknown time' ), 'missing timestamp reads "unknown time" — never a fabricated age' );

echo "\nGroup: findings builder — value keys missing (no fabricated 0s)\n";
// '?? 0' would render "(0 < 0)" — invented evidence. Mirror the timestamp
// path's honesty: array_key_exists, absent renders "unknown".
$no_values = array( 'time' => $NOW - 3600, 'day' => '2026-07-10', 'path' => '/x', 'class' => 'human' );
$f4 = sn_health_analytics_integrity_findings( $no_values, $NOW );
ok( 1 === count( $f4 ), 'a payload without views/pageview_visits still counts as a violation' );
ok( false !== strpos( $f4[0]['note'], '(views unknown, pageview_visits unknown)' ), 'absent value keys render "unknown" — the timestamp path\'s honesty, mirrored' );
ok( false === strpos( $f4[0]['note'], '(0 < 0)' ), 'no fabricated "(0 < 0)" evidence from ?? 0 defaults' );
$one_value          = $no_values;
$one_value['views'] = 1;
$f5 = sn_health_analytics_integrity_findings( $one_value, $NOW );
ok( false !== strpos( $f5[0]['note'], '(views 1, pageview_visits unknown)' ), 'one absent key → the present value pins, the absent one reads unknown' );

// ── The real check callback: behavior matrix ────────────────────────────────
echo "\nGroup: sn_health_check_analytics_integrity — no option\n";
$GLOBALS['__hai_options'] = array();
$check = sn_health_check_analytics_integrity();
ok( 0 === $check['count'] && array() === $check['findings'], 'no option → check PASSES (0 findings)' );
ok( 'Analytics integrity' === $check['label'], 'check label pinned' );
ok( false !== strpos( $check['fix_hint'], 'no violations recorded' ), 'pass-state hint says "no violations recorded"' );

echo "\nGroup: sn_health_check_analytics_integrity — fresh option\n";
$GLOBALS['__hai_options'] = array( 'sn_analytics_integrity_alert' => $rollup_alert );
$check2 = sn_health_check_analytics_integrity();
ok( 1 === $check2['count'], 'stored violation → check FLAGS (1 finding)' );
ok( false !== strpos( $check2['findings'][0]['note'], '(2 < 5)' ), 'flagged finding surfaces the stored payload values' );
ok( false !== strpos( $check2['fix_hint'], 'sn_analytics_integrity_alert' ), 'fix hint names the option to clear after investigating' );
ok( false !== strpos( $check2['fix_hint'], 'never expires' ), 'fix hint states the record never expires on its own' );
ok( $GLOBALS['__hai_options']['sn_analytics_integrity_alert'] === $rollup_alert,
	'the check is READ-ONLY: the stored alert is untouched (clearing is the owner\'s explicit call)' );

echo "\nGroup: sn_health_check_analytics_integrity — stale option (staleness matrix)\n";
$stale                    = $read_alert;
$stale['time']            = time() - ( 30 * DAY_IN_SECONDS ) - 3600; // 30 days ago, real clock (the callback uses time())
$GLOBALS['__hai_options'] = array( 'sn_analytics_integrity_alert' => $stale );
$check3 = sn_health_check_analytics_integrity();
ok( 1 === $check3['count'], 'a 30-day-old record still FLAGS — no silent age cutoff' );
ok( false !== strpos( $check3['findings'][0]['note'], '30d ago' ), 'the check says "last violation 30d ago" instead of pretending none happened' );

echo "\nGroup: sn_health_check_analytics_integrity — corrupt option FLAGS\n";
$GLOBALS['__hai_options'] = array( 'sn_analytics_integrity_alert' => 'garbage' );
$check4 = sn_health_check_analytics_integrity();
ok( 1 === $check4['count'], 'present-but-corrupt (non-array) option FLAGS — the docblock matrix says present (any shape) is never a green pass' );
ok( false !== strpos( $check4['findings'][0]['note'] ?? '', 'unreadable (corrupt)' ), 'the corrupt-state finding surfaces through the real check callback' );
ok( 'garbage' === $GLOBALS['__hai_options']['sn_analytics_integrity_alert'], 'read-only even when corrupt: the check never clears the mangled record' );

// ── Wiring pins (source containment — the harness can't boot the full scan) ─
echo "\nGroup: wiring — registration + loader\n";
$scan_src = (string) file_get_contents( __DIR__ . '/../inc/health-checks.php' );
ok( false !== strpos( $scan_src, "'analytics_integrity'" ) && false !== strpos( $scan_src, 'sn_health_check_analytics_integrity()' ),
	'sn_health_run_scan() registers the analytics_integrity check (feeds checks_total → the "N/N checks passed" widget)' );
$loader_src = (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
ok( false !== strpos( $loader_src, "inc/health-analytics-integrity.php" ),
	'the plugin loader requires inc/health-analytics-integrity.php' );
$read_src = (string) file_get_contents( __DIR__ . '/../inc/analytics-read.php' );
ok( false !== strpos( $read_src, 'health-analytics-integrity.php' ),
	'inc/analytics-read.php docblock names the REAL consumer (no more phantom "the Health scan reads it")' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
