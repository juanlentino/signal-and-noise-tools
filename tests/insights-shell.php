<?php
/**
 * Standalone test: Insights tab two-column shell contract (v6.42.0).
 *
 * Locks the rail reorganization: the tab renders inside .sn-shell, the scan
 * WORKFLOW (Run Analysis + recommendation cards) sits in the main column, and
 * the passive READOUTS (scan status, AI usage & spend, automation settings)
 * sit in the right rail — i.e. after the <aside class="sn-shell__rail"> marker.
 *
 * Standalone — no PHPUnit. Run: php tests/insights-shell.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

// ─── WP + plugin stubs ───────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'SN_INSIGHTS_CACHE_TTL' ) ) { define( 'SN_INSIGHTS_CACHE_TTL', 7 * 86400 ); }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts = null ) { return gmdate( $f, (int) $ts ); } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '2 hours'; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $e = true ) { $r = ( $a == $b ) ? ' checked' : ''; if ( $e ) { echo $r; } return $r; } }

$GLOBALS['__opts'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $n, $d = false ) { return $GLOBALS['__opts'][ $n ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $n, $v, $a = null ) { $GLOBALS['__opts'][ $n ] = $v; return true; } }

// Insights data-layer stubs (the render functions call these). NB:
// snt_ai_is_available() is defined by ai-bootstrap.php (required below) and
// returns false here with no AI client — fine, we assert on placement, not
// on the enabled/disabled state of the run buttons.
if ( ! function_exists( 'snt_insights_last_scan' ) ) {
	function snt_insights_last_scan() {
		if ( ! empty( $GLOBALS['__no_scan'] ) ) { return null; }
		return array(
			'scanned_at'      => time() - 3600,
			'elapsed_ms'      => 1234,
			'recommendations' => array(
				array(
					'id'             => 'r1',
					'type'           => 'write_about',
					'title'          => 'Write about X',
					'rationale'      => 'Because Y.',
					'evidence_pills' => array( '3 posts in 7 days' ),
					'target'         => array(),
				),
			),
		);
	}
}
if ( ! function_exists( 'snt_insights_state_read' ) ) { function snt_insights_state_read() { return array( 'dismissed_ids' => array(), 'done_ids' => array() ); } }
if ( ! function_exists( 'snt_insights_filter_active' ) ) { function snt_insights_filter_active( $recs ) { return $recs; } }
if ( ! function_exists( 'snt_narration_last' ) ) { function snt_narration_last() { return null; } }
if ( ! function_exists( 'snt_narration_enabled' ) ) { function snt_narration_enabled() { return false; } }
if ( ! function_exists( 'snt_insights_weekly_cron_enabled' ) ) { function snt_insights_weekly_cron_enabled() { return false; } }

require_once __DIR__ . '/../inc/admin-shell.php';
require_once __DIR__ . '/../inc/ai-bootstrap.php';
require_once __DIR__ . '/../inc/insights-admin.php';

// Populate the AI usage log so the spend section renders a real readout.
$now = time();
update_option(
	'sn_ai_usage_log',
	array(
		array( 'ts' => $now - 100, 'feature' => 'insights', 'model' => 'claude-sonnet-4-6', 'served_model' => 'claude-sonnet-4-6', 'prompt' => 1000, 'completion' => 500, 'total' => 1500 ),
	)
);

function ish_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Test: Insights tab renders main+rail shell with workflow in main, readouts in rail\n";
ob_start();
snt_insights_render_admin_tab();
$html = ob_get_clean();

$rail_marker = '<aside class="sn-shell__rail"';
$rail_at = strpos( $html, $rail_marker );

ish_assert( false !== strpos( $html, '<div class="sn-shell">' ), 'tab is wrapped in the two-column shell' );
ish_assert( false !== strpos( $html, '<div class="sn-shell__main">' ), 'has a main column' );
ish_assert( false !== $rail_at, 'has a right rail' );

// Workflow content in MAIN (before the rail marker).
$run_at = strpos( $html, 'Run Analysis' );
$rec_at = strpos( $html, 'Write about X' );
ish_assert( false !== $run_at && $run_at < $rail_at, 'Run Analysis sits in the main column' );
ish_assert( false !== $rec_at && $rec_at < $rail_at, 'recommendation card sits in the main column' );

// Phase 1 widen: the recommendation cards are wrapped in a 2-up grid container
// (the .sn-rec-grid) so they sit side-by-side at wide widths. The grid is in
// the MAIN column (before the rail) and the recommendation card is INSIDE it.
$grid_at = strpos( $html, 'sn-rec-grid' );
ish_assert( false !== $grid_at && is_int( $rail_at ) && $grid_at < $rail_at, 'recommendation-card grid container is in the main column' );
ish_assert( false !== $grid_at && false !== $rec_at && $grid_at < $rec_at, 'recommendation card renders inside the grid container' );
// Run Analysis + Weekly digest stay full main-width (outside the rec grid):
// the grid must open AFTER Run Analysis, not wrap it.
ish_assert( false !== $grid_at && false !== $run_at && $run_at < $grid_at, 'Run Analysis renders before (outside) the recommendation grid' );

// Readouts in RAIL (after the rail marker).
$usage_at = strpos( $html, 'AI usage' );
$status_at = strpos( $html, 'Last scan' );
$settings_at = strpos( $html, 'Run a weekly scan automatically' );
// is_int( $rail_at ) gates against a false (missing-rail) spurious pass:
// strpos returns false when the aside is absent, and `$x > false` coerces
// to `$x > 0`, which any positive offset satisfies.
ish_assert( is_int( $rail_at ) && false !== $usage_at && $usage_at > $rail_at, 'AI usage & spend sits in the rail' );
ish_assert( is_int( $rail_at ) && false !== $status_at && $status_at > $rail_at, 'scan status box sits in the rail' );
ish_assert( is_int( $rail_at ) && false !== $settings_at && $settings_at > $rail_at, 'automation settings sit in the rail' );

// Balanced shell (2 shell divs + N inner, but at least the aside closes).
ish_assert( 1 === substr_count( $html, '<aside class="sn-shell__rail"' ) && 1 === substr_count( $html, '</aside>' ), 'rail aside is opened once and closed once' );

// ─── Scenario B: no scan yet — empty-state status still in the rail ──
echo "\nScenario B: no scan — empty-state status in rail, Run Analysis in main\n";
$GLOBALS['__no_scan'] = true;
ob_start();
snt_insights_render_admin_tab();
$html_b    = ob_get_clean();
$rail_b    = strpos( $html_b, '<aside class="sn-shell__rail"' );
$noscan_at = strpos( $html_b, 'No scan run yet' );
$run_b     = strpos( $html_b, 'Run Analysis' );
ish_assert( is_int( $rail_b ) && false !== $noscan_at && $noscan_at > $rail_b, 'no-scan status sits in the rail' );
ish_assert( is_int( $rail_b ) && false !== $run_b && $run_b < $rail_b, 'Run Analysis stays in the main column with no scan' );
ish_assert( 1 === substr_count( $html_b, '<aside class="sn-shell__rail"' ) && 1 === substr_count( $html_b, '</aside>' ), 'rail aside balanced with no scan' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
