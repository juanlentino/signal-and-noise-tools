<?php
/**
 * Tests for snt_analytics_render_controls in inc/analytics-admin-render.php.
 * Run: php tests/analytics-controls-render.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_RANGES', array( 7, 14, 30, 90, 365 ) );

function add_query_arg( $args, $base = '' ) { return $base . '?' . http_build_query( $args ); }
function remove_query_arg( $keys, $url = '' ) { return $url; }
function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="testnonce">'; }
function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return $s; }
function __( $s, $d = '' ) { return $s; }

require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

function capture_controls( $range, $class ) { ob_start(); snt_analytics_render_controls( $range, $class ); return ob_get_clean(); }

echo "\nGroup: D3 — the ONE range control\n";
$html = capture_controls( 90, 'human' );
ok( strpos( $html, '<details class="sn-an-range">' ) !== false, 'one control: details wrapper present' );
ok( strpos( $html, 'sn-an-range-current">Last 90 days<' ) !== false, 'summary names the current range' );
ok( strpos( $html, '>7d<' ) !== false && strpos( $html, '>14d<' ) !== false && strpos( $html, '>1y<' ) !== false && strpos( $html, '>All<' ) !== false, 'rolling row: all six entries' );
ok( strpos( $html, 'sn_range=365' ) !== false && strpos( $html, 'sn_range=all' ) !== false, 'rolling links intact' );
ok( strpos( $html, 'sn_range=this-week' ) !== false && strpos( $html, '>This week<' ) !== false, 'calendar row: presets inside the control' );
ok( strpos( $html, 'name="sn_from"' ) !== false && strpos( $html, 'value="custom"' ) !== false, 'custom form lives inside the control' );
ok( strpos( $html, 'sn-an-daterange' ) === false, 'the old daterange disclosure is GONE' );
ok( substr_count( $html, ' active' ) === 3, '90d active: exactly 3 active marks (range entry + class + compare Off)' );
ok( false === (bool) preg_match( '/ active[^"]*"[^>]*sn_range=all/', $html ), 'All entry NOT active when 90 selected' );
ob_start(); snt_analytics_render_controls( 'custom', 'human', '2026-06-01', '2026-07-13' ); $hcus = ob_get_clean();
ok( strpos( $hcus, 'sn-an-range-current">2026-06-01 – 2026-07-13<' ) !== false, 'summary shows the custom window' );
ok( strpos( $hcus, '<details class="sn-an-range">' ) !== false && strpos( $hcus, ' open' ) === false, 'no auto-open (the summary already names the window)' );

echo "\nGroup: v9.34.0 — semantic quick-jumps + compare pills\n";
$html = capture_controls( 7, 'human' );
ok( strpos( $html, '>14d<' ) !== false, 'renders the rolling 14d pill' );
ok( strpos( $html, 'sn_range=this-week' ) !== false && strpos( $html, '>This week<' ) !== false && strpos( $html, 'sn_range=this-quarter' ) !== false, 'semantic periods render as quick-jump links' );
ok( strpos( $html, 'sn_compare=prev' ) !== false && strpos( $html, 'sn_compare=yoy' ) !== false, 'compare pills link prev + yoy' );
ob_start(); snt_analytics_render_controls( 7, 'human', '', '', 'prev' ); $hc = ob_get_clean();
ok( preg_match( '/<a class="button button-small active"[^>]*aria-pressed="true"[^>]*>Previous</', $hc ) === 1, 'active compare mode is marked pressed' );

echo "\nGroup: D3 — range label vocabulary\n";
ok( 'Last 7 days' === snt_analytics_range_label( 7 ), 'label: 7' );
ok( 'Last 14 days' === snt_analytics_range_label( 14 ), 'label: 14 (the token the D3 docblock hygiene added)' );
ok( 'Last 30 days' === snt_analytics_range_label( '30' ), 'label: string 30' );
ok( 'Last year' === snt_analytics_range_label( 365 ), 'label: 365' );
ok( 'All history' === snt_analytics_range_label( 'all' ), 'label: all' );
ok( 'This quarter' === snt_analytics_range_label( 'this-quarter' ), 'label: preset' );
ok( '2026-06-01 – 2026-07-13' === snt_analytics_range_label( 'custom', '2026-06-01', '2026-07-13' ), 'label: custom shows the raw ISO window (house compare-note idiom)' );
ok( 'Custom' === snt_analytics_range_label( 'custom', 'junk', '' ), 'label: malformed custom degrades to Custom' );
ok( 'weird' === snt_analytics_range_label( 'weird' ), 'label: junk token echoes raw (server already fell back)' );
ok( 7 === count( snt_analytics_preset_labels() ), 'preset labels: all 7 calendar tokens' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
