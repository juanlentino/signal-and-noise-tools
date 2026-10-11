<?php
/**
 * Tests: Machine Readers pure renderers that still paint the Monitoring leaf
 * (sensor pills, the edge readout) and the identity totals the views read.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }

require_once __DIR__ . '/lib/wp-admin-notice-stub.php'; // 17.4.4: every classic notice is wp_admin_notice() (#1618).
require __DIR__ . '/../inc/machine-readers-render.php';
require __DIR__ . '/../inc/machine-readers-basis.php'; // Unreleased: the control-token rule the strip shares with the summary.

$rows = array(
	array( 'family' => 'openai',    'surface' => 'llms',   'day' => '2026-07-27', 'hits' => 12 ),
	array( 'family' => 'openai',    'surface' => 'rights', 'day' => '2026-07-28', 'hits' => 3 ),
	array( 'family' => 'anthropic', 'surface' => 'html',   'day' => '2026-07-28', 'hits' => 5 ),
	array( 'family' => 'uptime',    'surface' => 'html',   'day' => '2026-07-28', 'hits' => 40 ),
);

echo "Group: static compliance map pins\n";
$ai = snt_mr_ai_training_families();
ok( in_array( 'openai', $ai, true ) && in_array( 'anthropic', $ai, true ), 'AI-training class includes openai + anthropic' );
ok( ! in_array( 'search', $ai, true ) && ! in_array( 'uptime', $ai, true ), 'search + uptime are NOT AI-training class' );

echo "\nGroup: the sensor pill checks the deployed version against the contract minimum\n";
$min_pills = snt_mr_sensor_pills( array( 'version' => '99.0.0' ), null, array( 'ok' => true ) );
ok( 'ok' === ( $min_pills[0][0] ?? '' ), 'a contract-satisfying version is an ok pill' );
$old_pills = snt_mr_sensor_pills( array( 'version' => '1.0.0' ), null, array( 'ok' => true ) );
ok( 'warn' === ( $old_pills[0][0] ?? '' ) && false !== stripos( (string) ( $old_pills[0][1] ?? '' ), 'outdated' ), 'a below-minimum version warns' );
ok( false !== strpos( (string) ( $old_pills[0][2] ?? '' ), 'or newer' ), 'and names the required version' );

echo "\nGroup: v10.1.1 — the sensor block copies the Analytics idiom\n";
$pills = snt_mr_sensor_pills(
	array( 'version' => '1.4.0', 'deployed_at' => '2026-07-28T18:07:56Z' ),
	array( 'last_check_ok' => '1', 'last_check_drift' => '' ),
	array( 'ok' => true )
);
ok( is_array( $pills ) && count( $pills ) >= 3, 'a pill per pipeline stage, like Analytics' );
foreach ( $pills as $p ) {
	ok( in_array( $p[0], array( 'ok', 'warn', 'unknown' ), true ), 'pill state is the Analytics vocabulary: ' . $p[0] );
}
$html = snt_mr_render_sensor_status( $pills );
ok( false !== strpos( $html, 'sn-an-pipeline-pills' ), 'uses the Analytics pill container class' );
ok( false !== strpos( $html, 'sn-an-pill sn-an-pill--ok' ), 'uses the Analytics pill classes, not a bespoke one' );
ok( false !== strpos( $html, 'sn-an-pill-mark' ), 'carries the Analytics check mark span' );
ok( false === strpos( $html, 'sn-mr-panel' ), 'the invented v10.0.1 panel class is gone' );

$warn = snt_mr_sensor_pills( null, null, array( 'ok' => false, 'error' => 'not_configured' ) );
$states = array();
foreach ( $warn as $p ) { $states[] = $p[0]; }
ok( in_array( 'warn', $states, true ), 'an unconfigured sensor shows a warn pill' );
$whtml = snt_mr_render_sensor_status( $warn );
ok( false !== strpos( $whtml, 'sn-an-pipeline-warn' ), 'and its explanation renders in the Analytics warn line, below the pills' );

echo "\nGroup: reachable-but-unversioned is a warn, not unreachable\n";
$unreported = snt_mr_sensor_pills(
	array( 'version' => '', 'reachable' => true, 'deployed_at' => '2026-08-13T01:49:33Z' ),
	null,
	array( 'ok' => true )
);
ok( 'warn' === ( $unreported[0][0] ?? '' ), 'reachable + empty version → warn' );
ok( 'Sensor reachable, version unreported' === ( $unreported[0][1] ?? '' ), 'label names reachable-but-unreported' );
ok( 'Sensor unreachable' !== ( $unreported[0][1] ?? '' ), 'label is NOT Sensor unreachable' );
$dead = snt_mr_sensor_pills( null, null, array( 'ok' => true ) );
ok( 'unknown' === ( $dead[0][0] ?? '' ) && 'Sensor unreachable' === ( $dead[0][1] ?? '' ), 'null info is still exactly Sensor unreachable' );

// ── v12.26.0: the identity row (signed_agent + markdown_requested) ──────────
//
// Both metrics have been normalized and tested since v12.16.0 / v12.24.0 and
// rendered NOWHERE. This is their door.
$rows_unmeasured = array(
	array( 'family' => 'openai', 'hits' => 900, 'signed_agent' => 'unmeasured', 'markdown_requested' => false ),
	array( 'family' => 'anthropic', 'hits' => 100, 'signed_agent' => 'unmeasured', 'markdown_requested' => true ),
);
$rows_measured = array(
	array( 'family' => 'openai', 'hits' => 60, 'signed_agent' => 'valid', 'markdown_requested' => true ),
	array( 'family' => 'openai', 'hits' => 30, 'signed_agent' => 'unsigned', 'markdown_requested' => false ),
	array( 'family' => 'perplexity', 'hits' => 10, 'signed_agent' => 'invalid', 'markdown_requested' => false ),
);

// THE LOAD-BEARING CASE. An hour after the sensor ships, every historical row
// reads 'unmeasured'. Rendering "0 verified" there would be a FALSE ZERO — it
// asserts a measurement that was never taken. Never-measured and measured-zero
// are different answers and must not render the same.
$t_un = snt_mr_identity_totals( $rows_unmeasured );
ok( 0 === $t_un['measured'], 'an all-unmeasured window reports zero MEASURED reads' );
ok( 1000 === $t_un['total'], 'while still counting the reads themselves' );

// With real signature data, the split is reported.
$t_m = snt_mr_identity_totals( $rows_measured );
ok( 100 === $t_m['measured'], 'measured counts every row that carries a real state' );
ok( 60 === $t_m['valid'], 'valid hits are summed' );
ok( 10 === $t_m['invalid'], 'invalid hits are summed separately — not folded into unsigned' );
ok( 30 === $t_m['unsigned'], 'unsigned is its own bucket' );

// ── v13.43.0: the truncation notice ─────────────────────────────────────────
//
// Worker v1.23.0 declared AGGREGATE_LIMIT = 10000 and began reporting
// `truncated` on every read. snt_mr_fetch() captures it and the summary payload
// carries it — but NO render path consulted it, so every figure on this tab
// rendered identically whether or not the read was capped. That is the one
// failure mode the worker change exists to make visible.
//
// It matters MORE here than in the payload: v13.34.0 moved the ability's
// `total` onto the day-only totals view, which cannot truncate, but the view's
// headline still sums the AGGREGATE.
// Under truncation a sum of capped rows does not look degraded — it looks like
// less traffic. So the notice must qualify the headline, not only the tables.
echo "\nGroup: truncation (v13.43.0): the Machine readers view says a capped read is a floor\n";
// Since the evidence-basis arc the figures live in the S&N Analytics Machine
// readers view; its composer reads the edge's flag and says the figures are a
// floor (tests/analytics-machine-views.php renders it).
$sn_views = (string) file_get_contents( __DIR__ . '/../inc/analytics-machine-views.php' );
ok( false !== strpos( $sn_views, '\'truncated\' => ! empty( $read[\'truncated\'] )' ), 'the Machine readers view carries the edge\'s truncated flag' );
ok( false !== strpos( $sn_views, 'every figure here is a floor, not a count' ), 'and says a capped read is a floor' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
