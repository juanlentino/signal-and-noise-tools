<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
function snt_analytics_sparkline( $series ) { return '<span class="sn-an-spark">SPARK' . count( $series ) . '</span>'; }

require __DIR__ . '/../inc/dash-zone-measurement.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard measurement zone\n\n";

$figs = sn_dash_measurement_figures( array(
	'views_7d' => 103, 'views_delta' => 39, 'ai_spend_30d' => 0.61,
	'anchored' => 33, 'citations' => 0, 'search_clicks' => 18,
) );
ok( count( $figs ) === 5, 'five figures, the agreed cap' );
ok( $figs[0]['key'] === 'views_7d', 'views leads — it is the hero row on narrow' );
ok( $figs[0]['hero'] === true, 'and it is flagged as the hero' );
foreach ( $figs as $f ) { ok( $f['measured'] === true, "{$f['key']} is measured when a value was supplied" ); }

// Money is money. Through the int cast $0.61 becomes "0" — the dashboard would
// report no AI spend on a month that had some, which is worse than reporting none.
$byk = array(); foreach ( $figs as $f ) { $byk[ $f['key'] ] = $f; }
ok( $byk['ai_spend_30d']['value'] === '$0.61', 'AI SPEND KEEPS ITS CENTS — not truncated to an int' );

// The delta belongs to views alone. Unguarded it staples the views delta onto
// every figure, so "clicks 7d" would show a change it never measured.
ok( $byk['views_7d']['delta'] === 39, 'the hero carries its delta' );
ok( $byk['search_clicks']['delta'] === null, 'A NON-HERO FIGURE NEVER BORROWS THE VIEWS DELTA' );
ok( $byk['citations']['delta'] === null, 'nor does citations' );

// A measured zero renders as zero.
$z = sn_dash_measurement_figures( array( 'views_7d' => 0, 'citations' => 0, 'ai_spend_30d' => 0, 'anchored' => 0, 'search_clicks' => 0 ) );
foreach ( $z as $f ) { ok( $f['measured'] === true, "{$f['key']} zero is MEASURED, not unknown" ); }
ok( $z[0]['value'] === '0', 'a measured zero prints 0' );
$zk = array(); foreach ( $z as $f ) { $zk[ $f['key'] ] = $f; }
ok( $zk['ai_spend_30d']['value'] === '$0.00', 'and a measured zero SPEND still prints as money' );

// An absent value is unknown, never zero. This is the zero-vs-null rule.
$u = sn_dash_measurement_figures( array( 'views_7d' => 103 ) );
$by = array(); foreach ( $u as $f ) { $by[ $f['key'] ] = $f; }
ok( $by['search_clicks']['measured'] === false, 'an absent Search Console read is UNKNOWN' );
ok( $by['search_clicks']['value'] !== '0', 'and it never renders as 0' );
ok( $by['views_7d']['measured'] === true, 'the supplied figure is still measured' );

// ── the strip renderer ──────────────────────────────────────────────────────
function strip( $data ) { ob_start(); sn_dash_render_measurement_strip( sn_dash_measurement_figures( $data ) ); return ob_get_clean(); }

$h = strip( array( 'views_7d' => 103, 'views_delta' => 39, 'ai_spend_30d' => 0.61, 'anchored' => 33, 'citations' => 0, 'search_clicks' => 18 ) );
ok( false !== strpos( $h, 'sn-dash-strip' ), 'the strip renders its wrapper' );
ok( false !== strpos( $h, 'sn-dash-fig--hero' ), 'the hero figure carries the hero class the reflow targets' );
ok( substr_count( $h, 'sn-dash-fig-value' ) === 5, 'all five figures render' );
ok( false !== strpos( $h, '$0.61' ), 'money keeps its cents in the markup' );
ok( false === strpos( $h, 'sn-dash-fig--unmeasured' ), 'nothing is marked unmeasured when everything was measured' );

// The unmeasured class is what the CSS dims. Without it an em dash looks like data.
$h = strip( array( 'views_7d' => 103 ) );
ok( false !== strpos( $h, 'sn-dash-fig--unmeasured' ), 'AN UNMEASURED FIGURE CARRIES THE CLASS THE CSS DIMS' );
ok( substr_count( $h, 'sn-dash-fig--unmeasured' ) === 4, 'and exactly the four that were never supplied' );

// Escaping: labels are ours, but values pass through the same door.
ok( false === strpos( strip( array( 'views_7d' => '<script>' ) ), '<script>' ), 'the strip escapes its values' );

// ── the sparkline ───────────────────────────────────────────────────────────
// Reuses the shared analytics helper — the same SVG treatment as the Overview
// chart — rather than minting a second sparkline.
$series = array( array( 'day' => '2026-08-13', 'views' => 4 ), array( 'day' => '2026-08-14', 'views' => 11 ) );

$figs = sn_dash_measurement_figures( array( 'views_7d' => 103, 'views_series' => $series ) );
$byk  = array(); foreach ( $figs as $f ) { $byk[ $f['key'] ] = $f; }
ok( $byk['views_7d']['series'] === $series, 'the hero figure carries the series as DATA — the builder stays pure' );
ok( $byk['citations']['series'] === null, 'no other figure carries a series' );

$h = strip( array( 'views_7d' => 103, 'views_series' => $series ) );
ok( false !== strpos( $h, 'sn-an-spark' ), 'the hero renders the shared sparkline' );
ok( substr_count( $h, 'sn-an-spark' ) === 1, 'exactly one sparkline on the strip' );

// No series, no sparkline — an empty chart is worse than no chart.
ok( false === strpos( strip( array( 'views_7d' => 103 ) ), 'sn-an-spark' ), 'no series means no sparkline' );
ok( false === strpos( strip( array( 'views_7d' => 103, 'views_series' => array() ) ), 'sn-an-spark' ), 'an EMPTY series draws nothing, not an empty chart' );

// The one that matters: never draw a trend for a figure we did not measure.
$h = strip( array( 'views_series' => $series ) );
ok( false !== strpos( $h, 'sn-dash-fig--unmeasured' ), 'views with no value is unmeasured' );
ok( false === strpos( $h, 'sn-an-spark' ), 'AN UNMEASURED FIGURE NEVER DRAWS A SPARKLINE' );

// The renderer is public, so pin its own guard rather than relying on the
// builder having nulled an empty series upstream. Handed one directly, the
// shared helper would emit its `--empty` marker; the strip must draw nothing.
ob_start();
sn_dash_render_measurement_strip( array(
	array( 'key' => 'views_7d', 'label' => 'views 7d', 'hero' => true, 'measured' => true, 'value' => '9', 'series' => array() ),
) );
$direct = ob_get_clean();
ok( false !== strpos( $direct, 'sn-dash-fig--hero' ), 'the hand-built hero renders' );
ok( false === strpos( $direct, 'sn-an-spark' ), 'AN EMPTY SERIES HANDED STRAIGHT TO THE RENDERER DRAWS NOTHING' );

// ── the search window is whatever the last sync used ────────────────────────
// The stored Search Console window is 28 days by default, not 7. A figure
// labelled "clicks 7d" over a month of data is a four-fold overstatement that
// reads as perfectly plausible, so the label is DERIVED from the real window.
$f28 = sn_dash_measurement_figures( array( 'search_clicks' => 412, 'search_clicks_days' => 28 ) );
$k28 = array(); foreach ( $f28 as $f ) { $k28[ $f['key'] ] = $f; }
ok( $k28['search_clicks']['value'] === '412', 'the click total renders' );
ok( false !== strpos( $k28['search_clicks']['label'], '28' ), 'THE LABEL REPORTS THE REAL WINDOW, not a hardcoded 7d' );
ok( false === strpos( $k28['search_clicks']['label'], '7d' ), 'and never claims 7d for a 28-day window' );

$f7 = sn_dash_measurement_figures( array( 'search_clicks' => 9, 'search_clicks_days' => 7 ) );
$k7 = array(); foreach ( $f7 as $f ) { $k7[ $f['key'] ] = $f; }
ok( false !== strpos( $k7['search_clicks']['label'], '7' ), 'a genuine 7-day window says 7' );

// An unknown window length must not invent one.
$f0 = sn_dash_measurement_figures( array( 'search_clicks' => 5 ) );
$k0 = array(); foreach ( $f0 as $f ) { $k0[ $f['key'] ] = $f; }
ok( false === strpos( $k0['search_clicks']['label'], '0' ), 'an unknown window length does not render "clicks 0d"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
