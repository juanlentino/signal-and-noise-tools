<?php
/**
 * Tests: the Dashboard metaboxes.
 *
 * The load-bearing property here is the TITLE DOT. Core lets a user collapse a
 * box, so if state lived only in the body a collapsed Systems box would hide an
 * open finding — the same guarantee the superseded pin design made, in a new
 * mechanic.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return '/wp-admin/' . $p; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }

require __DIR__ . '/../inc/admin-glance.php';
require __DIR__ . '/../inc/dash-boxes.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard boxes\n\n";

// ── the title dot ───────────────────────────────────────────────────────────
$t = snt_dash_box_title( 'Systems', 'attention' );
ok( false !== strpos( $t, 'sn-dash-dot--attention' ), 'THE STATE DOT IS IN THE TITLE, so a collapsed box still reports state' );
ok( false !== strpos( $t, 'Systems' ), 'the title text survives' );
ok( false === strpos( snt_dash_box_title( 'Fleet', 'ok' ), 'attention' ), 'a calm box carries no attention class' );
ok( false === strpos( snt_dash_box_title( '<script>x</script>', 'ok' ), '<script>' ), 'the title is escaped' );
ok( false === strpos( snt_dash_box_title( 'Fleet', '"><b>' ), '<b>' ), 'a hostile state cannot break out of the class attribute' );

// ── rows ────────────────────────────────────────────────────────────────────
$cards = array(
	array( 'label' => 'Health', 'value' => '0 findings', 'pill' => array( 'kind' => 'ok', 'text' => 'clear' ), 'href' => '/wp-admin/x' ),
	array( 'label' => 'Caches', 'value' => '1/3', 'pill' => array( 'kind' => 'warn', 'text' => 'stale' ) ),
);
ob_start(); snt_dash_render_rows( $cards ); $h = ob_get_clean();
ok( false !== strpos( $h, 'sn-dash-rows' ), 'rows render their wrapper' );
ok( 2 === substr_count( $h, 'sn-dash-row"' ), 'one element per card' );
ok( false !== strpos( $h, 'sn-dash-dot--warn' ), 'a card carries its pill kind as a dot' );
ok( false !== strpos( $h, '<a href="/wp-admin/x">' ), 'a card with an href becomes a link to the tab that owns it' );
ok( false !== strpos( $h, '<span>Caches</span>' ), 'a card without one does not' );

// Attention leads, reusing the shipped sort rather than a second ordering rule.
ok( strpos( $h, 'Caches' ) < strpos( $h, 'Health' ), 'THE WARNING LEADS — the v10.48.0 sort still governs order' );

// Empty renders nothing at all, not an empty wrapper.
ob_start(); snt_dash_render_rows( array() ); $e = ob_get_clean();
ok( '' === $e, 'no cards means no markup, not an empty list' );

// Escaping, on both halves of a row.
ob_start(); snt_dash_render_rows( array( array( 'label' => '<script>a</script>', 'value' => '<script>b</script>' ) ) ); $x = ob_get_clean();
ok( false === strpos( $x, '<script>' ), 'row label and value are both escaped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
