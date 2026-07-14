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

echo "\nGroup: snt_an_trend_svg — adopter parity seams (T2 review: wrap_attrs / aria_label / class opts)\n";
// wrap_attrs: the Overview canonical puts the brush data attrs ON .sn-spark-wrap
// (inc/analytics-render-overview.php:79-83, pinned by tests/analytics-admin.php) —
// without this seam "brush stays at the caller" is impossible.
$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ), array( 'wrap_attrs' => 'data-x="1"' ) ); } );
ok( false !== strpos( $h, '<div class="sn-spark-wrap" data-x="1">' ), 'wrap_attrs lands inside the .sn-spark-wrap open tag' );
$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ) ); } );
ok( false !== strpos( $h, '<div class="sn-spark-wrap">' ), 'default wrap_attrs: bare wrap, byte-identical to today' );

// aria_label: both head-bearing adopters carry DISTINCT translated aria strings
// (Overview 'Daily/Weekly views trend', login-defense 'Daily blocked trend',
// bot-trend 'Bot share trend' vs head 'Bot share') — head-fallback alone would
// silently rename them for screen readers.
$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ), array( 'head' => 'Views per day', 'aria_label' => 'Daily views trend' ) ); } );
ok( false !== strpos( $h, 'aria-label="Daily views trend"' ) && false === strpos( $h, 'aria-label="Views per day"' ), 'explicit aria_label wins over the head fallback' );
$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ), array( 'aria_label' => 'Daily blocked trend' ) ); } );
ok( false !== strpos( $h, 'aria-label="Daily blocked trend"' ), 'aria_label lands even with no head' );
$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ), array( 'head' => 'Views per day' ) ); } );
ok( false !== strpos( $h, 'aria-label="Views per day"' ), 'omitted aria_label falls back to head' );
$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ) ); } );
ok( false === strpos( $h, 'aria-label' ), 'head and aria_label both absent: no aria-label attribute (trajectory precedent, posts-admin.php:185)' );

// wrap_class / svg_class: bot-trend's sn-an-bot-trend wrapper + .sn-an-bot-spark svg
// are pinned (tests/analytics-bottrend-render.php) AND load-bearing in
// analytics-admin.css — without these opts bot-trend is a forced holdout.
$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ), array( 'wrap_class' => 'sn-an-bot-trend', 'svg_class' => 'sn-an-bot-spark' ) ); } );
ok( false !== strpos( $h, '<div class="sn-an-bot-trend">' ) && false === strpos( $h, 'sn-overview-trend' ), 'wrap_class overrides the outer wrapper' );
ok( false !== strpos( $h, '<svg class="sn-an-bot-spark"' ) && false === strpos( $h, 'class="sn-spark"' ), 'svg_class overrides the svg class' );
$h = cap( function () { snt_an_trend_svg( array( 10, 40, 20 ) ); } );
ok( false !== strpos( $h, '<div class="sn-overview-trend">' ) && false !== strpos( $h, '<svg class="sn-spark"' ), 'defaults stay the canonical sn-overview-trend + sn-spark, byte-identical' );

echo "\nGroup: snt_an_kv_table — THE k/v table (D5 §4, edge dims + login-defense top tables)\n";
$h = cap( function () {
	snt_an_kv_table(
		'Edge locations',
		array( array( 'IAD', '900', '5 MB' ), array( 'ORD', '400', '2 MB' ) ),
		array( 'Edge locations', 'Requests', 'Bandwidth' )
	);
} );
ok( false !== strpos( $h, 'class="postbox sn-an-postbox"' ), 'kv table: panel chrome via the shared primitive (sn-an-postbox)' );
ok( false !== strpos( $h, 'inside sn-an-table-inside' ), 'kv table: body class is the table-inside idiom' );
ok( false !== strpos( $h, '<th scope="col" class="manage-column column-primary">Edge locations</th>' ), 'kv table: primary col header from cols[0]' );
ok( false !== strpos( $h, '<th scope="col" class="manage-column num">Requests</th>' ) && false !== strpos( $h, '<th scope="col" class="manage-column num">Bandwidth</th>' ), 'kv table: numeric col headers from cols[1..]' );
ok( false !== strpos( $h, '<td class="column-primary"><strong>IAD</strong></td>' ), 'kv table: primary cell bold, no data-colname by default' );
ok( false !== strpos( $h, '<td class="num">900</td>' ) && false !== strpos( $h, '<td class="num">5 MB</td>' ), 'kv table: numeric cells pre-formatted, class="num"' );
ok( false === strpos( $h, 'data-colname' ), 'kv table: data_colname off by default (login-defense parity)' );

$h = cap( function () {
	snt_an_kv_table(
		'Edge locations',
		array( array( 'IAD', '900' ) ),
		array( 'Edge locations', 'Requests' ),
		array( 'data_colname' => true )
	);
} );
ok( false !== strpos( $h, 'data-colname="Edge locations"' ) && false !== strpos( $h, 'data-colname="Requests"' ), 'kv table: data_colname=true emits data-colname on every cell (edge idiom)' );

// esc_html()/esc_attr() are identity stubs in this file (see the top-of-file
// stubs, shared by every group above) — real escaping is pinned against
// tests/edge-render-dim.php's htmlspecialchars stubs instead. This just proves
// row cells and headers route THROUGH esc_html()/esc_attr() (content survives
// the round trip) rather than being dropped or reordered.
$h = cap( function () {
	snt_an_kv_table( 'Weird & Co', array( array( 'A & B', '1' ) ), array( 'Weird & Co', 'N' ) );
} );
ok( false !== strpos( $h, 'A & B' ), 'kv table: row cell content survives the esc_html() call' );
ok( false !== strpos( $h, 'Weird & Co' ), 'kv table: header content survives the esc_html() call' );

$GLOBALS['sn_an_empty_panels'] = array();
$h = cap( function () { snt_an_kv_table( 'Edge locations', array(), array( 'Edge locations', 'Requests' ), array( 'empty' => 'No edge-location data in this range yet.' ) ); } );
ok( '' === $h, 'kv table: empty rows render no panel markup' );
$kv_noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $kv_noted ) && 'Edge locations' === $kv_noted[0]['title'] && 'No edge-location data in this range yet.' === $kv_noted[0]['why'], 'kv table: empty rows fold with title + why' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
