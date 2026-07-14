<?php
/**
 * Primitive-contract tests (v9.40.0 D4): the ONE delta badge, the ONE KPI row,
 * the ONE config/dormant gate. Run: php tests/analytics-primitives.php
 * @since plugin v9.40.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function __( $s, $d = '' ) { return $s; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }

require __DIR__ . '/../inc/analytics-panels.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function cap( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }

echo "Group: snt_an_delta_badge — kpi variant (byte-parity with the old renderer)\n";
$h = cap( function () { snt_an_delta_badge( array( 'pct' => 12, 'dir' => 'up', 'previous' => 1000 ), array( 'variant' => 'kpi' ) ); } );
ok( false !== strpos( $h, 'sn-kpi-delta sn-delta-up' ) && false !== strpos( $h, '+12%' ), 'kpi up: class + signed pct' );
ok( false !== strpos( $h, 'title="previous period: 1,000"' ), 'kpi: prior-period tooltip' );
$h = cap( function () { snt_an_delta_badge( array( 'pct' => 12, 'dir' => 'up', 'previous' => 1000 ), array( 'variant' => 'kpi', 'basis_label' => 'same period last year' ) ); } );
ok( false !== strpos( $h, 'title="same period last year: 1,000"' ), 'kpi: basis label rides the tooltip' );
$h = cap( function () { snt_an_delta_badge( array( 'pct' => null, 'dir' => 'up' ), array( 'variant' => 'kpi' ) ); } );
ok( false !== strpos( $h, '>new<' ) || false !== strpos( $h, ' new</span>' ), 'kpi: null pct + up = "new"' );
ok( '' === cap( function () { snt_an_delta_badge( null, array( 'variant' => 'kpi' ) ); } ), 'kpi: null delta = silent no-op' );
ok( '' === cap( function () { snt_an_delta_badge( array( 'pct' => 5 ), array( 'variant' => 'kpi' ) ); } ), 'kpi: missing dir = silent no-op' );
$h = cap( function () { snt_an_delta_badge( array( 'pct' => 0, 'dir' => 'flat' ), array( 'variant' => 'kpi' ) ); } );
ok( false !== strpos( $h, 'sn-delta-flat' ) && false !== strpos( $h, '■' ), 'kpi: flat dir renders the flat class + square' );

echo "\nGroup: snt_an_delta_badge — inline variant\n";
$h = cap( function () { snt_an_delta_badge( array( 'pct' => -8, 'dir' => 'down' ) ); } );
ok( false !== strpos( $h, 'sn-an-delta sn-an-delta--down' ) && false !== strpos( $h, '-8%' ), 'inline down: legacy classes + signed pct (default variant)' );

echo "\nGroup: snt_an_kpi_row — the ONE card loop\n";
$cards = array(
	array( 'l' => 'Views', 'n' => '1,234', 'delta' => array( 'pct' => 5, 'dir' => 'up' ), 'promoted' => true ),
	array( 'l' => 'Now', 'n' => '3', 'live' => true ),
	array( 'l' => 'Bounce', 'n' => '40%', 'sub' => 'of 120 visits' ),
	array( 'l' => 'Cache hit', 'n' => '98%' ),
);
$h = cap( function () use ( $cards ) { snt_an_kpi_row( $cards ); } );
ok( 1 === substr_count( $h, 'sn-kpi-promoted' ), 'promoted flag renders once' );
ok( false !== strpos( $h, 'sn-delta-up' ) && false !== strpos( $h, '+5%' ), 'delta card routes through the badge' );
ok( false !== strpos( $h, '>live<' ), 'live card renders the live slot' );
ok( false !== strpos( $h, 'sn-delta-flat">of 120 visits' ), 'sub card renders flat descriptor' );
ok( false !== strpos( $h, 'no change' ), 'bare card defaults to "no change"' );
$sc = cap( function () { snt_an_kpi_row( array( array( 'l' => 'Cooling', 'n' => '4', 'sub' => 'losing steam', 'sub_class' => 'sn-delta-down' ) ) ); } );
ok( false !== strpos( $sc, 'sn-delta-down">losing steam' ), 'sub_class overrides the default flat class on a sub descriptor' );
$h = cap( function () use ( $cards ) { snt_an_kpi_row( $cards, array( 'empty_slot' => 'omit', 'row_class' => 'sn-kpi-row--edge' ) ); } );
ok( false === strpos( $h, 'no change' ), 'empty_slot=omit suppresses the default slot (edge idiom)' );
ok( false !== strpos( $h, 'sn-kpi-row sn-kpi-row--edge' ), 'row_class rides the wrapper' );
$h = cap( function () { snt_an_kpi_row( array( array( 'n' => 'orphan' ), 'not-an-array' ) ); } );
ok( '<div class="sn-kpi-row"></div>' === $h, 'malformed cards degrade silently (empty row, no notice)' );

echo "\nGroup: snt_an_gate — the ONE config/dormant gate\n";
$h = cap( function () { snt_an_gate( 'Edge', 'Not configured yet.', 'Configure →', 'https://x/wp-admin/admin.php?page=sn-theme-options' ); } );
ok( false !== strpos( $h, 'postbox' ) && false !== strpos( $h, 'sn-an-gate' ), 'gate renders panel chrome + marker class' );
ok( false !== strpos( $h, 'Not configured yet.' ) && false !== strpos( $h, 'Configure →' ), 'message + CTA' );
ok( false !== strpos( $h, 'button button-small' ) && false === strpos( $h, 'button-primary' ), 'default CTA weight stays button-small' );
$h = cap( function () { snt_an_gate( 'Analytics', 'Add credentials.', 'Configure →', 'https://x/wp-admin/admin.php', array( 'cta_primary' => true ) ); } );
ok( false !== strpos( $h, 'button button-primary' ) && false === strpos( $h, 'button-small' ), 'cta_primary: first-run gates keep the primary CTA weight' );
$h = cap( function () { snt_an_gate( 'Posts', 'No published posts yet.' ); } );
ok( false === strpos( $h, '<a ' ), 'no CTA when label/url absent' );

echo "\nGroup: the empty-state fold — plain line vs details diagnostics\n";
$GLOBALS['sn_an_empty_panels'] = array();
snt_an_note_empty( 'TLS versions' );
snt_an_note_empty( 'Time zones' );
$h = cap( 'snt_an_flush_empty_fold' );
ok( false !== strpos( $h, '<p class="sn-an-empty sn-an-empty-fold">' ) && false !== strpos( $h, 'TLS versions &middot; Time zones' ), 'no whys: plain line, byte-shape unchanged' );
snt_an_note_empty( 'LCP (field)', 'Needs the web-vitals beacon + worker v1.8.0 + traffic.' );
snt_an_note_empty( 'Time zones' );
$h = cap( 'snt_an_flush_empty_fold' );
ok( false !== strpos( $h, '<details class="sn-an-empty-fold">' ) && false !== strpos( $h, '<summary' ), 'with whys: details shape' );
ok( false !== strpos( $h, 'LCP (field)' ) && false !== strpos( $h, 'web-vitals beacon' ), 'diagnostic listed' );
ok( false !== strpos( $h, 'Time zones' ) && 1 === substr_count( $h, '<li' ), 'why-less panel stays summary-only (one li)' );
$h = cap( 'snt_an_flush_empty_fold' );
ok( '' === $h, 'collector resets after flush' );
// Legacy third-party callers may still push raw title strings straight into the
// global — flush normalizes them to { title, why: '' } (summary-only, no li).
$GLOBALS['sn_an_empty_panels'][] = 'Legacy plain-string entry';
$h = cap( 'snt_an_flush_empty_fold' );
ok( false !== strpos( $h, '<p class="sn-an-empty sn-an-empty-fold">' ) && false !== strpos( $h, 'Legacy plain-string entry' ), 'legacy plain-string entry normalizes into the plain summary line' );
ok( false === strpos( $h, '<li' ) && false === stripos( $h, 'warning' ), 'legacy entry: no li, no PHP warning leaked' );

echo "\nGroup: snt_an_trend_svg — THE trend renderer (D5 §3, geometry from the Overview canonical)\n";
// Pinned 3-point series [10,40,20]: n=3, w=600 -> step=300 -> px=[0,300,600].
// max=40 (peak at index 1) -> py = round(78-(v/40)*70,2) = [60.5, 8, 43].
$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ) ); } );
ok( false !== strpos( $h, 'viewBox="0 0 600 84"' ) && false !== strpos( $h, 'class="sn-spark"' ), 'SVG present with the canonical viewBox/width' );
ok( false !== strpos( $h, 'd="M 0,60.5' ), "path d begins at the first point's x/y (0,60.5)" );
ok( false !== strpos( $h, ' 300,8' ), 'the peak (v=40 of 40) maps to the top padding (y=8)' );

// Shared y-max: series=[0,100,50] (own max=100) + overlay=[0,200] -> shared max=200.
// Main series py = round(78-(v/200)*70,2) = [78, 43, 60.5] — v=100 (its OWN peak)
// no longer maps to top=8, proving the overlay's larger value raised the shared scale.
$h = cap( function () { snt_an_trend_svg( array( 0, 100, 50 ), array( 'overlay_series' => array( 0, 200 ) ) ); } );
ok( false !== strpos( $h, 'd="M 0,78' ), 'main path begins at (0,78) under the shared max' );
ok( false !== strpos( $h, ' 300,43' ) && false === strpos( $h, ' 300,8' ), "series' own peak (100) does NOT hit top=8 once the overlay (200) raises the shared max" );
ok( false !== strpos( $h, 'stroke-dasharray="4 3"' ) && false !== strpos( $h, 'stroke="#a7aaad"' ), 'overlay_series renders a second, dashed path' );
ok( false !== strpos( $h, ' 600,8' ), "overlay's own peak (200 of shared max 200) maps to top=8" );

$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ), array( 'stroke' => '#d63638' ) ); } );
ok( false !== strpos( $h, 'stroke="#d63638" stroke-width="2"' ), 'stroke opt changes the main line stroke' );

ok( '' === cap( function () { snt_an_trend_svg( array( 5 ) ); } ), 'fewer than 2 points (1) -> complete silence' );
ok( '' === cap( function () { snt_an_trend_svg( array() ); } ), 'fewer than 2 points (0) -> complete silence' );

$h1 = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ) ); } );
$h2 = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ), array( 'id_suffix' => '-cmp' ) ); } );
ok( false !== strpos( $h1, 'url(#snSparkFill)' ) && false !== strpos( $h1, 'id="snSparkFill"' ), 'default id_suffix (\'\') reproduces the canonical gradient id' );
ok( false !== strpos( $h2, 'url(#snSparkFill-cmp)' ) && false !== strpos( $h2, 'id="snSparkFill-cmp"' ), 'id_suffix de-collides the gradient id' );

$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ), array( 'axis' => array( 'Jan 1', 'Jan 3' ) ) ); } );
ok( false !== strpos( $h, '<div class="sn-spark-axis"><span>Jan 1</span><span>Jan 3</span></div>' ), 'axis labels render when passed' );
$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ) ); } );
ok( false === strpos( $h, 'sn-spark-axis' ), 'axis row absent when not passed' );

$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ), array( 'head' => 'Views per day', 'meta' => 'peak 40' ) ); } );
ok( false !== strpos( $h, 'sn-trend-title">Views per day' ) && false !== strpos( $h, 'sn-trend-meta">peak 40' ), 'head/meta render when passed' );
$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ) ); } );
ok( false === strpos( $h, 'sn-trend-head' ), 'head/meta absent when not passed' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
