<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }

require __DIR__ . '/../inc/dash-zone-measurement.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard measurement zone\n\n";

$figs = sn_dash_measurement_figures( array(
	'views_7d' => 103, 'views_delta' => 39, 'ai_spend_30d' => 0.61,
	'anchored' => 33, 'citations' => 0, 'search_clicks_7d' => 18,
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
ok( $byk['search_clicks_7d']['delta'] === null, 'A NON-HERO FIGURE NEVER BORROWS THE VIEWS DELTA' );
ok( $byk['citations']['delta'] === null, 'nor does citations' );

// A measured zero renders as zero.
$z = sn_dash_measurement_figures( array( 'views_7d' => 0, 'citations' => 0, 'ai_spend_30d' => 0, 'anchored' => 0, 'search_clicks_7d' => 0 ) );
foreach ( $z as $f ) { ok( $f['measured'] === true, "{$f['key']} zero is MEASURED, not unknown" ); }
ok( $z[0]['value'] === '0', 'a measured zero prints 0' );
$zk = array(); foreach ( $z as $f ) { $zk[ $f['key'] ] = $f; }
ok( $zk['ai_spend_30d']['value'] === '$0.00', 'and a measured zero SPEND still prints as money' );

// An absent value is unknown, never zero. This is the zero-vs-null rule.
$u = sn_dash_measurement_figures( array( 'views_7d' => 103 ) );
$by = array(); foreach ( $u as $f ) { $by[ $f['key'] ] = $f; }
ok( $by['search_clicks_7d']['measured'] === false, 'an absent Search Console read is UNKNOWN' );
ok( $by['search_clicks_7d']['value'] !== '0', 'and it never renders as 0' );
ok( $by['views_7d']['measured'] === true, 'the supplied figure is still measured' );

// ── the strip renderer ──────────────────────────────────────────────────────
function strip( $data ) { ob_start(); sn_dash_render_measurement_strip( sn_dash_measurement_figures( $data ) ); return ob_get_clean(); }

$h = strip( array( 'views_7d' => 103, 'views_delta' => 39, 'ai_spend_30d' => 0.61, 'anchored' => 33, 'citations' => 0, 'search_clicks_7d' => 18 ) );
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
