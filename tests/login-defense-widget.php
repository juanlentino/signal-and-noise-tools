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

// Configured headline -> widget shows numbers + link.
function sn_login_defense_headline() {
	return array( 'configured' => true, 'checked' => 10, 'blocked' => 4, 'block_rate' => 40, 'top_network' => 'BadNet' );
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

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
