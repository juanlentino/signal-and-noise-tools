<?php
/**
 * Tests: the ops wall renderer — the detail columns at the foot of the screen.
 *
 * SCOPE NOTE (v11.30.0). This suite used to own the whole console: the band,
 * the rail and the stage. That composition is gone — the rail became the
 * systems grid (inc/dash-systems.php), the figures became signals carrying
 * comparisons (inc/dash-signals.php), and the composition contract now lives in
 * tests/dash-screen.php. What remains here is the renderer that turns panel
 * DATA into columns, which is the one piece that survived all three redesigns
 * unchanged — because it never encoded a layout in the first place.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
foreach ( array( 'esc_html', 'esc_attr', 'esc_html__', 'esc_attr__' ) as $f ) {
	if ( ! function_exists( $f ) ) { eval( "function $f(\$s,\$d=''){ return htmlspecialchars((string)\$s, ENT_QUOTES); }" ); }
}
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a, $n = '_wpnonce', $r = true ) { echo '<input type="hidden">'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return '/wp-admin/' . $p; } }
require __DIR__ . '/../inc/admin-glance.php';
require __DIR__ . '/../inc/dash-verdict.php';
require __DIR__ . '/../inc/dash-signals.php';
require __DIR__ . '/../inc/dash-systems.php';
require __DIR__ . '/../inc/dash-trend.php';
require __DIR__ . '/../inc/dash-ops-render.php';
require __DIR__ . '/../inc/dash-console.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function panel( $t, $rows, $x = array() ) {
	return array_merge( array( 'title' => $t, 'rows' => $rows, 'empty' => 'Nothing in the window', 'unmeasured' => 'Not measured' ), $x );
}
function wall( $panels ) { ob_start(); sn_dash_render_ops( $panels ); return ob_get_clean(); }
echo "the ops wall renderer\n\n";

$rows = array(
	array( 'label' => 'provenance over detection', 'value' => '4' ),
	array( 'label' => '/notes/two-kinds', 'value' => '18', 'href' => '/n', 'dot' => 'ok' ),
);
$w = wall( array( panel( 'Recent deploys', $rows ), panel( 'Top queries', null ), panel( 'Top pages', array() ) ) );

ok( false !== strpos( $w, 'sn-scr__detail' ), 'the wall renders as detail columns' );
ok( 3 === substr_count( $w, 'sn-scr__col"' ), 'ONE COLUMN PER PANEL — three in, three out; the wall is data, not hand-written markup' );
ok( false === strpos( $w, 'sn-ops__panel' ),
	'AND NOT AS BORDERED CARDS — ten drawn boxes on one screen is ten rectangles of non-data pixels (Few, data-pixel ratio)' );
ok( false !== strpos( $w, 'provenance over detection' ), 'a row renders its label' );
ok( false !== strpos( $w, '<a class="sn-ops__label" href="/n">' ), 'a row with an href links' );
ok( false !== strpos( $w, 'sn-rail__dot--ok' ) || false !== strpos( $w, 'dot--ok' ), 'a row with a state renders a dot' );

// ABSENT IS NOT ZERO, per panel.
ok( 1 === substr_count( $w, 'Not measured' ), 'the null panel — and ONLY it — reads not-measured' );
ok( 1 === substr_count( $w, 'Nothing in the window' ), 'the empty panel — and ONLY it — reads measured-empty' );

ok( '' === trim( wall( array() ) ), 'NO PANELS RENDERS NOTHING — an empty grid is banked whitespace wearing a border' );

$evil = wall( array( panel( '<script>t</script>', array( array( 'label' => '<script>l</script>', 'value' => '<script>v</script>' ) ) ) ) );
ok( false === strpos( $evil, '<script>t' ) && false === strpos( $evil, '<script>l' ) && false === strpos( $evil, '<script>v' ),
	'title, label and value are all escaped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
