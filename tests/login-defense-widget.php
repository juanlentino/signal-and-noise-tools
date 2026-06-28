<?php
/**
 * CLI fixture for the login-defense dashboard widget render.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$fails  = 0;
$passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n ) { return (string) $n; }
function admin_url( $p ) { return '/wp-admin/' . $p; }
function add_action( $h, $cb ) {}

require __DIR__ . '/../inc/login-defense-widget.php';

// Configured headline -> widget shows numbers + link. Global-driven so the
// dormant state can be exercised too (v6.47.0).
$GLOBALS['__ld_headline'] = array( 'configured' => true, 'checked' => 10, 'blocked' => 4, 'block_rate' => 40, 'top_network' => 'BadNet' );
function sn_login_defense_headline() {
	return $GLOBALS['__ld_headline'];
}
ob_start();
sn_login_defense_widget_render();
$w = ob_get_clean();
ok(
	strpos( $w, '4' ) !== false && strpos( $w, '40%' ) !== false
	&& strpos( $w, 'BadNet' ) !== false && strpos( $w, 'View login defense' ) !== false,
	'widget shows blocked/rate/top-network + link'
);
ok( strpos( $w, 'page=sn-analytics&sn_view=login-defense' ) !== false, 'widget links to the Analytics dashboard login-defense view' );
ok( strpos( $w, 'tab=monitoring&sub=login-defense' ) === false, 'widget no longer links to the old Monitoring sub-tab' );

// v6.38.0: the widget now reuses the analytics widgets' .sn-aw-* visual vocabulary
// (the shared analytics-widget.css is already enqueued on the Dashboard home screen)
// instead of the unstyled bare-<ul> .sn-lg-widget it shipped with.
ok( strpos( $w, 'sn-aw-grid' ) !== false, 'blocked + block-rate render in the shared .sn-aw-grid KPI grid' );
ok( strpos( $w, '<div class="sn-aw-stat-n">4</div>' ) !== false, 'blocked count renders as an .sn-aw-stat tile (parity with the analytics widgets)' );
ok( strpos( $w, '<div class="sn-aw-stat-n">40%</div>' ) !== false, 'block rate renders as an .sn-aw-stat tile' );
ok( strpos( $w, 'sn-aw-foot' ) !== false, 'top-network + link use the shared .sn-aw-foot footer treatment' );
ok( strpos( $w, 'sn-lg-widget' ) === false, 'the unstyled .sn-lg-widget bare-<ul> class is gone' );

// v6.44.0: surface the 7d denominator (the returned-but-previously-ignored `checked`
// total) so the block rate has volume context — 40% of 10 reads very differently
// from 40% of 10,000.
ok( strpos( $w, '4 of 10' ) !== false, 'foot surfaces the blocked-of-checked denominator (7d) using the returned checked total' );

// v6.47.0 (audit #5/#12): the dormant state adopts the analytics widgets' styled
// .sn-aw-err treatment (not a bare <p>) and points at the same edge-worker
// prerequisite, so all three home widgets read as one design system when CF is off.
$GLOBALS['__ld_headline'] = array( 'configured' => false );
ob_start();
sn_login_defense_widget_render();
$empty = ob_get_clean();
ok( strpos( $empty, 'sn-aw-err' ) !== false, 'dormant state uses the styled .sn-aw-err class (parity with the analytics widgets)' );
ok( strpos( $empty, 'edge worker' ) !== false, 'dormant copy points at the shared edge-worker prerequisite' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
