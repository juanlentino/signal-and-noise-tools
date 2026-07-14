<?php
/**
 * Standalone test: snt_edge_render_dim() omits empty panels (v8.5.2).
 * Run: php tests/edge-render-dim.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function number_format_i18n( $n ) { return (string) (int) $n; }
function __( $s, $d = null ) { return $s; } // D5 §6/§8: snt_edge_render_dim() now wraps its Requests/Bandwidth column headers

require_once __DIR__ . '/../inc/analytics-panels.php';
require_once __DIR__ . '/../inc/edge-admin.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

unset( $GLOBALS['sn_an_empty_panels'] );
ob_start(); snt_edge_render_dim( 'Edge locations', array(), 'No edge-location data yet.', true ); $e = ob_get_clean();
ok( '' === trim( $e ), 'empty edge dim renders no panel' );
// v9.40.0 D4: the collector now stores { title, why } shape (fold contract in
// tests/analytics-primitives.php) instead of a plain title string.
$edge_noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $edge_noted ) && 'Edge locations' === $edge_noted[0]['title'], 'empty edge dim registers its title' );
// D5 §4: $empty was silently dropped before (the last dead diagnostic param in
// the plugin) — it must now reach the fold's <li> "why" text.
ok( 'No edge-location data yet.' === ( $edge_noted[0]['why'] ?? '' ), 'empty edge dim forwards its $empty diagnostic into the fold (D5 §4)' );

ob_start(); snt_edge_render_dim( 'Status codes', array( array( 'value' => '2xx', 'requests' => 5, 'bytes' => 0 ) ), 'x', false ); $h = ob_get_clean();
ok( strpos( $h, '2xx' ) !== false && strpos( $h, 'postbox' ) !== false, 'renders a panel when rows present' );

// D5 §4: adopted via the shared snt_an_kv_table — pin the exact byte shape
// (postbox chrome, data-colname on every cell, Requests + Bandwidth columns,
// number_format_i18n() + snt_edge_fmt_bytes() pre-formatted at this call site)
// stays byte-identical to the pre-adoption hand-rolled markup.
ob_start();
snt_edge_render_dim( 'Edge locations', array( array( 'value' => 'IAD', 'requests' => 900, 'bytes' => 5242880 ) ), 'unused', true );
$w = ob_get_clean();
ok( strpos( $w, '<td class="column-primary" data-colname="Edge locations"><strong>IAD</strong></td>' ) !== false,
	'byte-parity: primary cell keeps its data-colname + bold value' );
ok( strpos( $w, '<td class="num" data-colname="Requests">900</td>' ) !== false,
	'byte-parity: requests cell pre-formatted via number_format_i18n() at the call site' );
// This file doesn't stub size_format() (no WP loaded), so snt_edge_fmt_bytes()
// takes its own number_format_i18n()+' B' fallback branch — "5242880 B", not
// a human MB string. That's this test file's pre-existing behavior, unchanged
// by the kv-table adoption (tests/edge-admin.php's size_format() stub covers
// the human-readable path end-to-end).
ok( strpos( $w, '<td class="num" data-colname="Bandwidth">5242880 B</td>' ) !== false,
	'byte-parity: bandwidth cell pre-formatted via snt_edge_fmt_bytes() at the call site' );

// Row values are escaped (this file's esc_html()/esc_attr() are real
// htmlspecialchars() stubs, unlike tests/analytics-primitives.php's identity ones).
ob_start();
snt_edge_render_dim( 'Threats', array( array( 'value' => '<script>x</script>', 'requests' => 1, 'bytes' => 0 ) ), 'unused', false );
$x = ob_get_clean();
ok( strpos( $x, '<script>' ) === false && strpos( $x, '&lt;script&gt;' ) !== false, 'row values are escaped through the shared kv table' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
